<?php
/**
 * Local credential checks before workspace storage or native AI requests.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Detect recognizable credentials without recording or returning their values. */
final class Bhranti_Credentials {
	/**
	 * Share the same bounded text rules with both administration clients.
	 *
	 * @return array Public patterns, never matched text.
	 */
	public static function rules() {
		return array(
			'patterns'   => array(
				'\\bsk-(?:proj-|ant-api[0-9]+-)?[a-z0-9_-]{12,}\\b',
				'\\b(?:gh[pousr]_[a-z0-9]{20,}|github_pat_[a-z0-9_]{20,})\\b',
				'\\bxox[baprs]-[a-z0-9-]{10,}\\b',
				'\\b(?:AKIA|ASIA)[A-Z0-9]{16}\\b',
				'\\bAIza[a-z0-9_-]{30,}\\b',
				'\\b(?:sk|rk)_(?:live|test)_[a-z0-9]{12,}\\b',
				'\\bBearer\\s+(?!(?:authentication|authorization|credentials|tokens?|works?|headers?|schemes?)\\b)[a-z0-9+/_=.-]{8,}|\\bAuthorization\\s*[:=]\\s*Basic\\s+[a-z0-9+/_=.-]{8,}',
				'\\beyJ[a-z0-9_-]{8,}\\.[a-z0-9_-]{8,}\\.[a-z0-9_-]{8,}',
				'-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----|-----BEGIN PGP PRIVATE KEY BLOCK-----',
				'\\b[a-z][a-z0-9+.-]*://[^\\s/@:]+:[^\\s/@]+@',
			),
			'binding'    => <<<'REGEX'
\b(?:password|passwd|pwd|passphrase|user[_ -]?pass|db[_ -]?password|api[_ -]?key|(?:access|refresh|auth|id)[_ -]?token|(?:client|consumer)[_ -]?secret|(?:secret|private|signing|encryption)[_ -]?key|aws[_ -]?secret[_ -]?access[_ -]?key|(?:auth|secure_auth|logged_in|nonce)_(?:key|salt)|authorization|application[_ -]?password|recovery[_ -]?(?:code|phrase)|seed[_ -]?phrase|mnemonic|secret)["'`]?\s*(?::|=|\bis\b|,\s*(?=["']))\s*(\[[^\]\r\n]*\]|\{[^}\r\n]*\}|<[^>\r\n]*>|"[^"\r\n]*"|'[^'\r\n]*'|`[^`\r\n]*`|[^\s,;<>}\]]+)
REGEX
			,
			'safeValues' => array( 'required', 'optional', 'missing', 'empty', 'unknown', 'redacted', 'removed', 'hidden', 'encrypted', 'not', 'never', 'a', 'an', 'the', 'reset', 'incorrect', 'invalid', 'expired', 'changed', 'protected', 'strong', 'weak', 'secure', 'insecure', 'set', 'unset', 'configured', 'unconfigured', 'available', 'unavailable', 'example', 'placeholder', 'none', 'null', 'false', 'true', 'bearer', 'basic' ),
		);
	}

	/** Register one shared browser detector; PHP remains authoritative. */
	public static function enqueue() {
		wp_enqueue_script( 'bhranti-credentials', plugins_url( 'assets/credentials.js', BHRANTI_FILE ), array(), BHRANTI_VERSION, true );
		wp_add_inline_script( 'bhranti-credentials', 'window.bhrantiCredentialRules = ' . wp_json_encode( self::rules(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * Check text and structured keys; callers must omit image bytes.
	 *
	 * @param mixed $value Text or bounded structured data.
	 * @param int   $depth Current nesting depth.
	 * @return bool
	 */
	public static function contains( $value, $depth = 0 ) {
		if ( $depth > 32 ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( self::contains( $item, $depth + 1 ) || ( is_string( $key ) && self::contains( $key . ' = bhranti_probe' ) && self::bound_value( $key, $item, $depth + 1 ) ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$text  = self::normalize( $value );
		$rules = self::rules();
		// A credential label and its value may occupy adjacent HTML elements.
		// Mark only an exact label as a binding before stripping tags; prose such as
		// "How to reset password" remains ordinary text.
		$adjacent = preg_replace_callback(
			'#(?:<[a-z][a-z0-9:-]*\b[^>]*>\s*){1,4}([^<>]{1,80})(?:\s*</[a-z][a-z0-9:-]*\s*>\s*){1,4}(?=<[a-z][a-z0-9:-]*\b[^>]*>)#iu',
			static function ( $html_match ) use ( $rules ) {
				$probe = trim( $html_match[1] ) . '=bhranti_probe';
				return preg_match( '~\A' . str_replace( '~', '\\~', $rules['binding'] ) . '\z~iu', $probe, $bound ) && 'bhranti_probe' === $bound[1] ? $html_match[1] . ': ' : $html_match[0];
			},
			$text
		);
		foreach ( array_unique( array( $text, preg_replace( '/<[^>]*>/s', '', $text ), preg_replace( '/<[^>]*>/s', '', $adjacent ) ) ) as $candidate ) {
			foreach ( $rules['patterns'] as $pattern ) {
				if ( preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~iu', $candidate ) ) {
					return true;
				}
			}
			preg_match_all( '~' . $rules['binding'] . '~iu', $candidate, $matches );
			foreach ( $matches[1] as $matched ) {
				$matched = strtolower( trim( $matched, " \t\r\n\"'`" ) );
				if ( '' !== $matched && ! in_array( rtrim( $matched, '.!?' ), $rules['safeValues'], true ) && ! preg_match( '/^(?:[\[<{](?:YOUR(?:[_ -][a-z0-9_-]+)*|EXAMPLE(?:[_ -][a-z0-9_-]+)*|REDACTED|TOKEN|PASSWORD|API[_ -]?KEY|SENSITIVE TEXT WITHHELD)[\]}>]|(?:YOUR|EXAMPLE|REDACTED)(?:[_ -][a-z0-9_-]+)*|\*{2,}|x{3,}|\.{3,}|[_-]{2,})$/i', $matched ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Check scalar leaves under a credential-labeled structured key.
	 *
	 * @param string $key   Structured key to check.
	 * @param mixed  $value Nested value.
	 * @param int    $depth Current nesting depth.
	 * @return bool Whether a credential was found.
	 */
	private static function bound_value( $key, $value, $depth ) {
		if ( $depth > 32 ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::bound_value( $key, $item, $depth + 1 ) ) {
					return true;
				}
			}
			return false;
		}
		return is_scalar( $value ) && self::contains( $key . ' = ' . (string) $value, $depth + 1 );
	}

	/**
	 * Decode common pasted/JSON/HTML representations, without executing markup.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function normalize( $value ) {
		for ( $pass = 0; $pass < 2; ++$pass ) {
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$value = preg_replace_callback(
				'/\\\\u([a-f0-9]{4})/i',
				static function ( $entity ) {
					return html_entity_decode( '&#x' . $entity[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				},
				$value
			);
			$value = str_replace( array( '\\"', "\\'", '\\/' ), array( '"', "'", '/' ), $value );
			$plain = rawurldecode( $value );
			$value = 1 === preg_match( '//u', $plain ) ? $plain : preg_replace( '/[\x80-\xFF]/', '', $plain );
		}
		$value = strtr(
			$value,
			array(
				'“' => '"',
				'”' => '"',
				'‘' => "'",
				'’' => "'",
			)
		);
		return preg_replace( '/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $value );
	}

	/**
	 * Memory accepts preferences, never credential labels or opaque token values.
	 *
	 * @param mixed $text Proposed preference/evidence.
	 * @return bool
	 */
	public static function memory_secret( $text ) {
		if ( ! is_string( $text ) || self::contains( $text ) ) {
			return true;
		}
		$text = self::normalize( $text );
		if ( preg_match( '/(?:password|passwd|passphrase|credential|authorization|bearer|api[_ -]?key|access[_ -]?token|secret|private[_ -]?key|recovery[_ -]?(?:phrase|code)|seed[_ -]?phrase|mnemonic)/i', $text ) ) {
			return true;
		}
		preg_match_all( '/[a-z0-9+\/_=-]{16,}/i', $text, $tokens );
		foreach ( $tokens[0] as $token ) {
			if ( preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $token ) ) {
				continue;
			}
			if ( ( preg_match( '/[a-z]/i', $token ) && preg_match( '/[0-9]/', $token ) ) || preg_match( '/[+\/=]/', $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Withhold sensitive text in a model-result copy, preserving receipts/status.
	 *
	 * @param mixed $value Tool result data.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$sensitive_container = is_string( $key ) && is_array( $item ) && self::contains( $key . ' = bhranti_probe' ) && self::bound_value( $key, $item, 0 );
				$value[ $key ]       = $sensitive_container || ( is_string( $key ) && is_scalar( $item ) && self::contains( array( $key => $item ) ) ) ? '[Sensitive text withheld]' : self::redact( $item );
			}
			return $value;
		}
		return is_string( $value ) && self::contains( $value ) ? '[Sensitive text withheld]' : $value;
	}

	/**
	 * Fixed warnings never echo the detected text or a request payload.
	 *
	 * @param string $context Where text was found.
	 * @return WP_Error
	 */
	public static function error( $context = 'message' ) {
		$messages = array(
			'message'  => __( 'This message or its attached text appears to contain a password, API key or access token. Remove it before sending. This request was not saved or sent to AI.', 'bhranti' ),
			'history'  => __( 'This conversation contains older text that looks like a credential. Start a new chat without it. This AI request was blocked.', 'bhranti' ),
			'settings' => __( 'These instructions appear to contain credentials. Remove passwords, API keys and access tokens before saving. Configure AI through WordPress Settings → Connectors.', 'bhranti' ),
			'provider' => __( 'Bhranti stopped because text for the AI appears to contain credentials. Review attached content and instructions, or start a new chat. This step was not sent to AI.', 'bhranti' ),
			'response' => __( 'Bhranti withheld a response containing possible credentials. No action from that response was run.', 'bhranti' ),
			'memory'   => __( 'Memory cannot store passwords, keys, tokens or recovery phrases. Save only language, tone, design or workflow preferences.', 'bhranti' ),
		);
		return new WP_Error(
			'bhranti_credentials',
			$messages[ $context ] ?? $messages['message'],
			array(
				'status'             => 400,
				'credential_context' => isset( $messages[ $context ] ) ? $context : 'message',
			)
		);
	}
}
