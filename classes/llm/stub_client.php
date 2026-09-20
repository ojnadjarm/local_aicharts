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

/**
 * Offline client answering from bundled sample answers, so the plugin works without a provider.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_client implements client_interface {
    /** @var string Answer returned for a request that is out of scope. */
    protected const REFUSAL = '{"status":"refused"}';

    /** @var string Start of a request that asks only how to draw known columns. */
    protected const CHART_REQUEST = 'columns:';

    /** @var int How many answers have been produced in this request. */
    protected static int $callcount = 0;

    /** @var array|null Decoded sample answers. */
    protected static ?array $fixtures = null;

    #[\Override]
    public function generate(array $messages, array $schema): llm_response {
        self::$callcount++;
        $fixtures = self::fixtures();
        $prompt = \core_text::strtolower($this->last_user_message($messages));

        foreach ($fixtures['refusalkeywords'] as $keyword) {
            if (str_contains($prompt, $keyword)) {
                return llm_response::answer(self::REFUSAL);
            }
        }

        if (str_starts_with($prompt, self::CHART_REQUEST)) {
            return llm_response::answer(json_encode($fixtures['responses']['default']['chart']));
        }

        foreach ($fixtures['responses'] as $keyword => $response) {
            if ($keyword !== 'default' && str_contains($prompt, $keyword)) {
                return llm_response::answer(json_encode($response));
            }
        }

        if (!array_intersect($this->words($prompt), $fixtures['vocabulary'])) {
            return llm_response::answer(self::REFUSAL);
        }

        return llm_response::answer(json_encode($fixtures['responses']['default']));
    }

    /**
     * How many answers this client produced since the last reset.
     *
     * @return int
     */
    public static function get_call_count(): int {
        return self::$callcount;
    }

    /**
     * Forget the call count.
     */
    public static function reset_call_count(): void {
        self::$callcount = 0;
    }

    /**
     * Text of the last user message.
     *
     * @param array $messages List of ['role' => string, 'content' => string].
     * @return string
     */
    protected function last_user_message(array $messages): string {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'user') {
                return (string) ($message['content'] ?? '');
            }
        }
        return '';
    }

    /**
     * Words of at least three letters in a prompt.
     *
     * @param string $prompt Lowercased prompt.
     * @return string[]
     */
    protected function words(string $prompt): array {
        preg_match_all('/[a-z]{3,}/', $prompt, $matches);
        return $matches[0];
    }

    /**
     * Read the sample answers, once per request.
     *
     * @return array
     */
    protected static function fixtures(): array {
        if (self::$fixtures === null) {
            self::$fixtures = json_decode(file_get_contents(__DIR__ . '/../../tests/fixtures/stub_responses.json'), true);
        }
        return self::$fixtures;
    }
}
