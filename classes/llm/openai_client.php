<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aicharts\llm;

use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Client for any OpenAI-compatible Chat Completions endpoint.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_client implements client_interface {
    /** @var string No API key is configured; nothing was sent. */
    public const ERROR_NOKEY = 'nokey';

    /** @var string The endpoint could not be reached or did not answer in time. */
    public const ERROR_UNREACHABLE = 'unreachable';

    /** @var string The endpoint answered with an error status. */
    public const ERROR_HTTP = 'httperror';

    /** @var string The endpoint answered 2xx but without a message content. */
    public const ERROR_BADANSWER = 'badanswer';

    /** @var string Path of the Chat Completions endpoint under the base URL. */
    protected const ENDPOINT = '/chat/completions';

    #[\Override]
    public function generate(array $messages, array $schema): llm_response {
        $config = get_config('local_aicharts');
        $apikey = trim((string) ($config->apikey ?? ''));
        if ($apikey === '') {
            return llm_response::error(self::ERROR_NOKEY, get_string('error_llm_nokey', 'local_aicharts'));
        }

        $body = [
            'model' => (string) ($config->model ?? ''),
            'messages' => $messages,
            'temperature' => (float) ($config->temperature ?? 0),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'chart_definition', 'strict' => false, 'schema' => $schema],
            ],
        ];
        $url = rtrim((string) ($config->baseurl ?? ''), '/') . self::ENDPOINT;

        try {
            $response = \core\di::get(http_client::class)->post($url, [
                RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $apikey],
                RequestOptions::JSON => $body,
                RequestOptions::TIMEOUT => max(1, (int) ($config->timeout ?? 60)),
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $e) {
            return llm_response::error(self::ERROR_UNREACHABLE, $this->sanitise($e->getMessage(), $apikey));
        }

        $status = $response->getStatusCode();
        $decoded = json_decode($response->getBody()->getContents(), true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            $message = "HTTP {$status}" . ($detail !== '' ? ": {$detail}" : '');
            return llm_response::error(self::ERROR_HTTP, $this->sanitise($message, $apikey));
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return llm_response::error(self::ERROR_BADANSWER, "HTTP {$status} without a message content");
        }
        return llm_response::answer($content);
    }

    /**
     * Strip the key from a message and keep it short.
     *
     * @param string $message Raw detail.
     * @param string $apikey The key that must never leak.
     * @return string
     */
    protected function sanitise(string $message, string $apikey): string {
        return \core_text::substr(str_replace($apikey, '***', $message), 0, 300);
    }
}
