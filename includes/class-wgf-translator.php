<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Translator {

	public static function get_supported_languages() {
		return [ 'de', 'fr', 'it', 'en' ];
	}

	public static function normalize_language( $language ) {
		$language = strtolower( trim( (string) $language ) );
		if ( $language === '' ) {
			return '';
		}

		$parts = explode( '-', $language );
		$base  = preg_replace( '/[^a-z]/', '', (string) $parts[0] );
		return strlen( $base ) === 2 ? $base : '';
	}

	public static function get_source_language() {
		$opts = WGF_Settings::get_settings();
		$src  = self::normalize_language( $opts['feed_source_language'] ?? 'de' );
		if ( $src === '' ) {
			$src = 'de';
		}
		return $src;
	}

	public static function is_enabled_for_language( $target_language ) {
		$opts = WGF_Settings::get_settings();
		if ( ( $opts['enable_feed_translation'] ?? 'no' ) !== 'yes' ) {
			return false;
		}
		if ( ( $opts['translation_provider'] ?? 'none' ) !== 'azure' ) {
			return false;
		}

		$target = self::normalize_language( $target_language );
		$source = self::get_source_language();
		if ( $target === '' || $target === $source ) {
			return false;
		}
		if ( ! in_array( $target, self::get_supported_languages(), true ) ) {
			return false;
		}

		return self::get_azure_key( $opts ) !== '';
	}

	public static function translate_strings( $strings, $target_language ) {
		$target_language = self::normalize_language( $target_language );
		if ( ! self::is_enabled_for_language( $target_language ) ) {
			return [];
		}

		$opts   = WGF_Settings::get_settings();
		$source = self::get_source_language();
		$ttl    = self::get_cache_ttl_seconds( $opts );

		$strings = is_array( $strings ) ? $strings : [];
		$unique  = [];
		$max_text_length = 4500;
		foreach ( $strings as $value ) {
			$value = trim( (string) $value );
			if ( $value === '' ) {
				continue;
			}
			if ( strlen( $value ) > $max_text_length ) {
				continue;
			}
			$unique[ $value ] = $value;
		}
		if ( empty( $unique ) ) {
			return [];
		}

		$translations = [];
		$missing      = [];
		foreach ( $unique as $text ) {
			$cache_key = self::build_cache_key( $source, $target_language, $text );
			$cached    = self::get_cached_translation( $cache_key );
			if ( $cached !== false && is_string( $cached ) && $cached !== '' ) {
				$translations[ $text ] = $cached;
			} else {
				$missing[] = $text;
			}
		}

		if ( ! empty( $missing ) ) {
			$fetched = self::request_azure_translations( $missing, $source, $target_language, $opts );
			foreach ( $fetched as $original => $translated ) {
				if ( $translated === '' ) {
					continue;
				}
				$translations[ $original ] = $translated;
				if ( $ttl > 0 ) {
					self::set_cached_translation( self::build_cache_key( $source, $target_language, $original ), $translated, $ttl );
				}
			}
		}

		return $translations;
	}

	protected static function get_cache_ttl_seconds( $opts ) {
		$hours = max( 1, intval( $opts['translation_cache_hours'] ?? 720 ) );
		return $hours * HOUR_IN_SECONDS;
	}

	protected static function build_cache_key( $source, $target, $text ) {
		return 'wgf_tr_' . md5( $source . '|' . $target . '|' . $text );
	}

	protected static function get_cached_translation( $cache_key ) {
		$record = get_option( $cache_key, null );
		if ( is_array( $record ) && isset( $record['value'] ) ) {
			$expires = isset( $record['expires'] ) ? intval( $record['expires'] ) : 0;
			if ( $expires <= 0 || $expires > time() ) {
				return is_string( $record['value'] ) ? $record['value'] : '';
			}
			delete_option( $cache_key );
		}

		$cached = get_transient( $cache_key );
		return ( is_string( $cached ) && $cached !== '' ) ? $cached : false;
	}

	protected static function set_cached_translation( $cache_key, $translated, $ttl ) {
		$translated = trim( (string) $translated );
		if ( $translated === '' ) {
			return;
		}

		$ttl = max( 1, intval( $ttl ) );
		update_option(
			$cache_key,
			[
				'value'   => $translated,
				'expires' => time() + $ttl,
			],
			false
		);
		set_transient( $cache_key, $translated, $ttl );
	}

	protected static function request_azure_translations( $texts, $source, $target, $opts ) {
		$key      = self::get_azure_key( $opts );
		$endpoint = self::get_azure_endpoint( $opts );
		$region   = self::get_azure_region( $opts );

		if ( $key === '' || $endpoint === '' ) {
			return [];
		}

		// Keep chunks modest on the free tier so successful blocks are cached incrementally.
		$max_items   = max( 1, intval( apply_filters( 'wgf_translator_chunk_max_items', 20 ) ) );
		$max_chars   = max( 200, intval( apply_filters( 'wgf_translator_chunk_max_chars', 5000 ) ) );
		$delay_ms    = max( 0, intval( apply_filters( 'wgf_translator_request_delay_ms', 350 ) ) );
		$max_retries = max( 1, min( 10, intval( apply_filters( 'wgf_translator_retry_attempts', 3 ) ) ) );
		$max_runtime = max( 3000, intval( apply_filters( 'wgf_translator_max_runtime_ms', 60000 ) ) );
		$deadline_ts = microtime( true ) + ( $max_runtime / 1000 );

		$url = add_query_arg(
			[
				'api-version' => '3.0',
				'from'        => $source,
				'to'          => $target,
			],
			$endpoint . '/translate'
		);

		$out = [];
		$chunks = self::chunk_texts( $texts, $max_items, $max_chars );
		foreach ( $chunks as $chunk_index => $chunk ) {
			if ( microtime( true ) >= $deadline_ts ) {
				break;
			}

			$payload = [];
			foreach ( $chunk as $text ) {
				$payload[] = [ 'text' => $text ];
			}

			$headers = [
				'Content-Type'                => 'application/json; charset=UTF-8',
				'Ocp-Apim-Subscription-Key'   => $key,
				'X-ClientTraceId'             => wp_generate_uuid4(),
			];
			if ( $region !== '' ) {
				$headers['Ocp-Apim-Subscription-Region'] = $region;
			}

			$response = self::request_azure_chunk_with_retry( $url, $headers, $payload, $max_retries, $deadline_ts );

			if ( is_wp_error( $response ) ) {
				// Slow down a bit even after failures to avoid burst loops.
				if ( $delay_ms > 0 ) {
					self::sleep_ms( $delay_ms );
				}
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				if ( $delay_ms > 0 ) {
					self::sleep_ms( $delay_ms );
				}
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				if ( $delay_ms > 0 ) {
					self::sleep_ms( $delay_ms );
				}
				continue;
			}

			foreach ( $chunk as $text_index => $original ) {
				$translated = '';
				if ( isset( $body[ $text_index ]['translations'][0]['text'] ) ) {
					$translated = trim( (string) $body[ $text_index ]['translations'][0]['text'] );
				}
				if ( $translated !== '' ) {
					$out[ $original ] = $translated;
				}
			}

			if ( $delay_ms > 0 && $chunk_index < ( count( $chunks ) - 1 ) && microtime( true ) < $deadline_ts ) {
				self::sleep_ms( $delay_ms );
			}
		}

		return $out;
	}

	protected static function request_azure_chunk_with_retry( $url, $headers, $payload, $max_attempts, $deadline_ts = 0 ) {
		$attempt = 0;
		$last    = new WP_Error( 'wgf_translator_unknown', 'Unknown Azure translator error.' );

		while ( $attempt < $max_attempts ) {
			if ( $deadline_ts > 0 && microtime( true ) >= $deadline_ts ) {
				return $last;
			}

			$attempt++;
			$last = wp_remote_post( $url, [
				'timeout' => 25,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			] );

			if ( ! is_wp_error( $last ) ) {
				$code = (int) wp_remote_retrieve_response_code( $last );
				if ( $code >= 200 && $code < 300 ) {
					return $last;
				}
				if ( ! self::should_retry_status_code( $code ) ) {
					return $last;
				}
				if ( $attempt >= $max_attempts ) {
					return $last;
				}
				$delay_ms = self::retry_delay_ms( $last, $attempt );
				if ( $deadline_ts > 0 ) {
					$remaining_ms = intval( max( 0, ( $deadline_ts - microtime( true ) ) * 1000 ) );
					if ( $remaining_ms <= 0 ) {
						return $last;
					}
					$delay_ms = min( $delay_ms, $remaining_ms );
				}
				self::sleep_ms( $delay_ms );
				continue;
			}

			if ( $attempt >= $max_attempts ) {
				return $last;
			}
			$delay_ms = self::network_retry_delay_ms( $attempt );
			if ( $deadline_ts > 0 ) {
				$remaining_ms = intval( max( 0, ( $deadline_ts - microtime( true ) ) * 1000 ) );
				if ( $remaining_ms <= 0 ) {
					return $last;
				}
				$delay_ms = min( $delay_ms, $remaining_ms );
			}
			self::sleep_ms( $delay_ms );
		}

		return $last;
	}

	protected static function should_retry_status_code( $code ) {
		return in_array( (int) $code, [ 408, 409, 425, 429, 500, 502, 503, 504 ], true );
	}

	protected static function retry_delay_ms( $response, $attempt ) {
		$ms_header = wp_remote_retrieve_header( $response, 'x-ms-retry-after-ms' );
		$ms        = intval( is_array( $ms_header ) ? reset( $ms_header ) : $ms_header );
		if ( $ms > 0 ) {
			return min( 15000, max( 200, $ms ) );
		}

		$retry_after = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( $retry_after !== '' ) {
			if ( ctype_digit( $retry_after ) ) {
				return min( 15000, max( 200, intval( $retry_after ) * 1000 ) );
			}
			$timestamp = strtotime( $retry_after );
			if ( $timestamp !== false ) {
				$delta_ms = max( 0, ( $timestamp - time() ) * 1000 );
				return min( 15000, max( 200, $delta_ms ) );
			}
		}

		$base   = min( 10000, 600 * pow( 2, max( 0, $attempt - 1 ) ) );
		$jitter = function_exists( 'random_int' ) ? random_int( 0, 250 ) : mt_rand( 0, 250 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		return intval( $base + $jitter );
	}

	protected static function network_retry_delay_ms( $attempt ) {
		$base   = min( 10000, 500 * pow( 2, max( 0, $attempt - 1 ) ) );
		$jitter = function_exists( 'random_int' ) ? random_int( 0, 200 ) : mt_rand( 0, 200 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		return intval( $base + $jitter );
	}

	protected static function sleep_ms( $ms ) {
		$ms = max( 0, intval( $ms ) );
		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}

	protected static function chunk_texts( $texts, $max_items, $max_chars ) {
		$chunks       = [];
		$current      = [];
		$current_size = 0;

		foreach ( $texts as $text ) {
			$text = (string) $text;
			$len  = strlen( $text );
			if ( $len <= 0 ) {
				continue;
			}

			$would_exceed_count = count( $current ) >= $max_items;
			$would_exceed_chars = ( $current_size + $len ) > $max_chars;
			if ( ! empty( $current ) && ( $would_exceed_count || $would_exceed_chars ) ) {
				$chunks[]     = $current;
				$current      = [];
				$current_size = 0;
			}

			$current[]    = $text;
			$current_size += $len;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	protected static function get_azure_key( $opts ) {
		if ( defined( 'WGF_AZURE_TRANSLATOR_KEY' ) && WGF_AZURE_TRANSLATOR_KEY ) {
			return trim( (string) WGF_AZURE_TRANSLATOR_KEY );
		}
		return trim( (string) ( $opts['azure_translator_key'] ?? '' ) );
	}

	protected static function get_azure_region( $opts ) {
		if ( defined( 'WGF_AZURE_TRANSLATOR_REGION' ) && WGF_AZURE_TRANSLATOR_REGION ) {
			return trim( (string) WGF_AZURE_TRANSLATOR_REGION );
		}
		return trim( (string) ( $opts['azure_translator_region'] ?? '' ) );
	}

	protected static function get_azure_endpoint( $opts ) {
		if ( defined( 'WGF_AZURE_TRANSLATOR_ENDPOINT' ) && WGF_AZURE_TRANSLATOR_ENDPOINT ) {
			$endpoint = trim( (string) WGF_AZURE_TRANSLATOR_ENDPOINT );
		} else {
			$endpoint = trim( (string) ( $opts['azure_translator_endpoint'] ?? 'https://api.cognitive.microsofttranslator.com' ) );
		}

		if ( $endpoint === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$endpoint = 'https://' . $endpoint;
		}

		return untrailingslashit( $endpoint );
	}
}
