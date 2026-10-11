<?php
/**
 * Allowlisted native AI failure diagnostics without provider payloads.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Classify native failures locally without changing a connector or retry policy. */
final class Bhranti_Diagnostics {

	/**
	 * Classify a native WP_Error using codes and exact local diagnostic literals.
	 *
	 * Runtime exceptions share prompt_builder_error in core. Their fixed local
	 * messages distinguish known failures, but no message or remote payload is
	 * returned, persisted, searched for substrings or sent back to a provider.
	 *
	 * @param mixed $error Native WordPress AI error.
	 * @return array Allowlisted code, reason, exception class and optional status.
	 */
	public static function classify( $error ) {
		$result = array(
			'code'            => 'bhranti_invalid_response',
			'reason'          => 'unknown',
			'exception_class' => 'unknown',
		);
		if ( ! is_wp_error( $error ) ) {
			return $result;
		}
		$codes = array( 'prompt_network_error', 'prompt_client_error', 'prompt_upstream_server_error', 'prompt_token_limit_reached', 'prompt_invalid_argument', 'prompt_builder_error', 'prompt_prevented', 'bhranti_provider_exception', 'bhranti_invalid_response' );
		$code  = $error->get_error_code();
		if ( in_array( $code, $codes, true ) ) {
			$result['code'] = $code;
		}
		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			if ( is_int( $data['status'] ?? null ) && $data['status'] >= 100 && $data['status'] <= 599 ) {
				$result['status'] = $data['status'];
			}
			$classes                   = array(
				'RuntimeException'                     => 'runtime',
				'InvalidArgumentException'             => 'invalid_argument',
				'WordPress\\AiClient\\Common\\Exception\\RuntimeException' => 'runtime',
				'WordPress\\AiClient\\Common\\Exception\\InvalidArgumentException' => 'invalid_argument',
				'WordPress\\AiClient\\Common\\Exception\\TokenLimitReachedException' => 'token_limit',
				'WordPress\\AiClient\\Providers\\Http\\Exception\\NetworkException' => 'network',
				'WordPress\\AiClient\\Providers\\Http\\Exception\\ClientException' => 'client',
				'WordPress\\AiClient\\Providers\\Http\\Exception\\ServerException' => 'server',
				'LittleMCP\\ChatGPT\\RequestException' => 'runtime',
			);
			$result['exception_class'] = is_string( $data['exception_class'] ?? null ) ? ( $classes[ $data['exception_class'] ] ?? 'unknown' ) : 'unknown';
		}
		$reasons          = array(
			'prompt_network_error'         => 'network',
			'prompt_upstream_server_error' => 'upstream',
			'prompt_token_limit_reached'   => 'output_limit',
			'prompt_invalid_argument'      => 'unsupported_prompt',
			'prompt_prevented'             => 'prevented',
		);
		$result['reason'] = $reasons[ $result['code'] ] ?? 'unknown';
		if ( in_array( $result['code'], array( 'prompt_client_error', 'prompt_builder_error' ), true ) ) {
			$statuses         = array(
				401 => 'authentication',
				403 => 'permission',
				429 => 'usage_limit',
			);
			$result['reason'] = $statuses[ $result['status'] ?? 0 ] ?? $result['reason'];
		}
		$message = $error->get_error_message();
		if ( ! is_string( $message ) || strlen( $message ) > 2048 ) {
			return $result;
		}
		$translations = get_translations_for_domain( 'littlemcp' );
		foreach ( self::literals() as $reason => $messages ) {
			foreach ( $messages as $literal ) {
				// Match the native connector's own localized literal without exposing it.
				if ( $literal === $message || $translations->translate( $literal ) === $message ) {
					$result['reason'] = $reason;
					return $result;
				}
			}
		}
		// Only anchored SDK templates are recognized; captured values are discarded.
		if ( preg_match( '/\ANo models found(?: for provider "[^"\r\n]{1,200}")? that support (?:text_generation|image_generation|text_to_speech_conversion|speech_generation|video_generation) for this prompt\.\z/', $message ) || preg_match( '/\AModel "[^"\r\n]{1,200}" does not support (?:text|image) generation\.\z/', $message ) ) {
			$result['reason'] = 'model_unavailable';
		}
		return $result;
	}

	/**
	 * Select a fixed actionable explanation, never a native error's text.
	 *
	 * @param array $diagnostic Classified diagnostic.
	 * @return string Safe plain-text user explanation.
	 */
	public static function message( $diagnostic ) {
		switch ( $diagnostic['reason'] ?? '' ) {
			case 'structured_output':
				return __( 'The AI returned an invalid action plan. No action ran from that response. Try a smaller request.', 'bhranti' );
			case 'incomplete_response':
			case 'invalid_response':
				return __( 'The AI response was incomplete. Completed actions are saved. Try a smaller request.', 'bhranti' );
			case 'output_limit':
				return __( 'The AI response exceeded its output limit. Completed actions are saved. Try a smaller request.', 'bhranti' );
			case 'authentication':
				return __( 'Your AI connection is unavailable. Check Settings > Connectors.', 'bhranti' );
			case 'permission':
			case 'prevented':
				return __( 'This AI request was blocked. Check connector approvals and your account permissions.', 'bhranti' );
			case 'model_unavailable':
				return __( 'No AI model can handle this request. Check the model in Settings > Connectors.', 'bhranti' );
			case 'network':
			case 'upstream':
				return __( 'The AI service is unavailable. Completed actions are saved. Check your connection and try again later.', 'bhranti' );
			case 'usage_limit':
				return __( 'Your AI account reached its usage limit. Try again after the limit resets.', 'bhranti' );
			case 'unsupported_prompt':
			case 'protocol_version':
				return __( 'The AI connector rejected this request. Check connector updates and the selected model.', 'bhranti' );
			default:
				return __( 'The AI request failed. Completed actions are saved. Check Logs for details.', 'bhranti' );
		}
	}

	/**
	 * Exact diagnostics discovered in the installed native SDK and connector.
	 *
	 * @return array<string, string[]> Reason keys and fixed local messages.
	 */
	private static function literals() {
		return array(
			'structured_output'   => array( 'ChatGPT returned invalid JSON for the requested structured output.' ),
			'incomplete_response' => array(
				'The ChatGPT stream ended during an event. No incomplete result was accepted.',
				'The ChatGPT stream ended before completion. No partial result was accepted.',
				'ChatGPT could not complete this response. No completed result was returned.',
				'ChatGPT returned invalid or unfinished output.',
			),
			'invalid_response'    => array(
				'ChatGPT returned an invalid stream event.',
				'ChatGPT returned an invalid output event.',
				'ChatGPT returned duplicate output events.',
				'ChatGPT returned multiple completion events.',
				'ChatGPT returned an invalid completion event.',
				'ChatGPT returned invalid completed output.',
				'ChatGPT returned a completed response without output.',
				'ChatGPT returned invalid output.',
				'ChatGPT returned unsupported non-text output.',
				'ChatGPT returned invalid message content.',
				'ChatGPT returned unsupported message content.',
				'ChatGPT returned no text in its completed response.',
				'ChatGPT returned invalid JSON.',
				'ChatGPT returned an invalid image result.',
				'ChatGPT returned invalid image data.',
				'ChatGPT returned invalid PNG image data.',
				'No text content found in first candidate',
			),
			'output_limit'        => array( 'The ChatGPT response exceeded the plugin response size limit. Request a smaller output.' ),
			'authentication'      => array(
				'The ChatGPT connection has invalid account credentials. Reconnect your account.',
				'The ChatGPT connection expired or was revoked. Reconnect your account.',
				'Your ChatGPT connection expired or was revoked. Connect again.',
				'Connect your ChatGPT account in Settings > Connectors.',
				'Reconnect your ChatGPT account.',
				'Your saved ChatGPT connection could not be read. Connect again.',
				'The WordPress user or ChatGPT connection changed. Create a new prompt for the current user.',
			),
			'permission'          => array(
				'Sign in to WordPress with an account allowed to use AI.',
				'ChatGPT requires a signed-in WordPress author.',
				'This ChatGPT connection cannot access the requested model or Codex service.',
				'WordPress AI blocked this request. Review Tools > Connector Approvals, then try again.',
			),
			'model_unavailable'   => array(
				'This model is unavailable to the current ChatGPT connection.',
				'The selected model is unavailable to this ChatGPT account. Choose another model in Settings > Connectors.',
				'No available ChatGPT models were returned for this account. Refresh the model list or check your selected account.',
			),
			'network'             => array(
				'The ChatGPT request could not reach OpenAI. Check connectivity and try again.',
				'OpenAI could not be reached to renew your connection. Try again later.',
			),
			'upstream'            => array( 'OpenAI is temporarily unavailable. Try again later.' ),
			'usage_limit'         => array(
				'Your ChatGPT plan usage limit has been reached. Try again after your limit resets.',
				'A ChatGPT plan usage limit applies to this connection. Check Usage in ChatGPT and try again later.',
			),
			'protocol_version'    => array( 'ChatGPT requires a newer Codex protocol version. Update LittleMCP and try again.' ),
			'unsupported_prompt'  => array(
				'ChatGPT rejected this request. Refresh the available model list and check the prompt configuration.',
				'ChatGPT rejected this request because required system instructions are missing.',
				'This ChatGPT model does not advertise image input. Choose an available vision model.',
				'The ChatGPT provider accepts user text and supported image inputs; this message part is unsupported.',
			),
		);
	}
}
