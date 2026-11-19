<?php

namespace Tests\Feature\Ai;

use App\Models\Message;
use App\Services\Ai\Notices\ApplicationNotices;
use App\Services\Ai\Notices\NoticeCode;
use App\Services\Ai\Notices\ReplyText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 / M3a: what the application writes under a coach reply is recorded as structured notices, and read back
 * without looking at a single word, so it is never taken for a workout, a request or any evidence of authorization.
 */
class ApplicationNoticesTest extends TestCase
{
    private function assistant(string $content, ?array $meta): Message
    {
        return new Message(['role' => Message::ROLE_ASSISTANT, 'content' => $content, 'meta' => $meta]);
    }

    /** A stored reply: the model's words, then the application's notices. */
    private function stored(string $model, array $notices): Message
    {
        $reply = ReplyText::fromModel($model);

        foreach ($notices as $code => $text) {
            $reply->with($code, $text);
        }

        return $this->assistant($reply->text(), $reply->meta() ?: null);
    }

    // ---- creation and persistence ------------------------------------------------------------------------------------

    public function test_a_reply_with_no_notice_stores_no_record_and_reads_back_whole(): void
    {
        $reply = ReplyText::fromModel("Here is your workout:\n1. Squats 3x10");

        $this->assertSame([], $reply->meta());
        $this->assertSame("Here is your workout:\n1. Squats 3x10", ApplicationNotices::modelText($this->assistant($reply->text(), null)));
    }

    public function test_one_notice_is_recorded_with_a_code_and_its_place(): void
    {
        $reply = ReplyText::fromModel('Nice session!')->with(NoticeCode::SAVE_FAILED, "I haven't changed your plan: the workout was not saved.");
        $record = $reply->meta()[ReplyText::META_KEY];

        $this->assertSame(1, $record['v']);
        $this->assertSame(mb_strlen($reply->text()), $record['length']);
        $this->assertSame(hash('sha256', $reply->text()), $record['sha256']);
        $this->assertSame([['code' => 'save_failed', 'start' => 13, 'end' => mb_strlen($reply->text())]], $record['segments']);
        $this->assertSame('Nice session!', $reply->modelText());
        $this->assertSame('Nice session!', ApplicationNotices::modelText($this->assistant($reply->text(), $reply->meta())));
    }

    public function test_several_notices_in_one_reply_are_all_recorded_in_order_and_all_removed(): void
    {
        $message = $this->stored('Done.', [
            NoticeCode::REPLACED_AS_ASKED => 'As you asked, I replaced “Old plan” on 2026-11-04 with this workout.',
            NoticeCode::RANGE_NOTE => 'Your plan keeps one number for each of these, so I saved the low end of every range: Squat 8 reps (written 8-12).',
            NoticeCode::LOAD_LEFT_UNSET => 'I left the starting weight for Squat unset: there is no recorded or planned weight to base one on.',
        ]);

        $segments = $message->meta[ReplyText::META_KEY]['segments'];

        $this->assertSame(['replaced_as_asked', 'range_note', 'load_left_unset'], array_column($segments, 'code'));
        $this->assertSame($segments[1]['start'], $segments[0]['end'], 'Each notice ends where the next begins.');
        $this->assertSame(mb_strlen($message->content), $segments[2]['end']);
        $this->assertSame('Done.', ApplicationNotices::modelText($message));
    }

    public function test_a_reply_the_application_wrote_whole_has_no_model_words(): void
    {
        $reply = ReplyText::application(NoticeCode::SAVE_QUESTION_PREFIX.'which_day', 'I haven\'t changed your plan: which day?');
        $message = $this->assistant($reply->text(), $reply->meta());

        $this->assertSame([['code' => 'save_question_which_day', 'start' => 0, 'end' => mb_strlen($reply->text())]], $reply->meta()[ReplyText::META_KEY]['segments']);
        $this->assertSame('', ApplicationNotices::modelText($message));
        $this->assertSame('', $reply->modelText());
    }

    public function test_replacing_the_reply_drops_the_models_words_and_records_the_whole_as_the_applications(): void
    {
        $reply = ReplyText::fromModel('The model said something.')->replaceWith(NoticeCode::REPLACEMENT_QUESTION, 'Do you want me to replace it?');

        $this->assertSame('Do you want me to replace it?', $reply->text());
        $this->assertSame('', ApplicationNotices::modelText($this->assistant($reply->text(), $reply->meta())));
    }

    public function test_an_empty_notice_is_not_recorded(): void
    {
        $reply = ReplyText::fromModel('Fine.')->with(NoticeCode::RANGE_NOTE, '  ');

        $this->assertSame('Fine.', $reply->text());
        $this->assertSame([], $reply->meta());
    }

    public function test_trailing_whitespace_on_the_models_words_does_not_leak_into_the_offsets(): void
    {
        $message = $this->stored("Here you go.  \n\n", [NoticeCode::SAVE_FAILED => 'I haven\'t changed your plan.']);

        $this->assertSame('Here you go.', ApplicationNotices::modelText($message));
        $this->assertSame("Here you go.\n\nI haven't changed your plan.", $message->content);
    }

    // ---- Unicode: offsets count characters, never bytes -----------------------------------------------------------------

    #[DataProvider('unicodeReplies')]
    public function test_offsets_survive_unicode_emoji_and_combining_characters(string $model): void
    {
        $message = $this->stored($model, [
            NoticeCode::SAVE_FAILED => "I haven't changed your plan: ñandú 💪🏽 \u{0065}\u{0301}.",
            NoticeCode::RANGE_NOTE => 'Rango: 日本語 🇨🇴.',
        ]);

        $this->assertSame(rtrim($model), ApplicationNotices::modelText($message));

        $record = $message->meta[ReplyText::META_KEY];
        $this->assertSame(mb_strlen($message->content), $record['length']);
        $this->assertGreaterThan(mb_strlen($message->content), strlen($message->content), 'The text has multi-byte characters, so bytes and characters differ.');
        $this->assertSame(mb_strlen(rtrim($model)), $record['segments'][0]['start'], 'Offsets are code points.');
    }

    public static function unicodeReplies(): array
    {
        return [
            'accents' => ['Aquí tienes: sentadillas 3x10, flexiones 3x12. ¡Ánimo!'],
            'emoji' => ["Great job 💪🏽🔥\nHere is the plan 🇨🇴"],
            'combining marks' => ["Cafe\u{0301} y pi\u{00F1}a, n\u{0303}and\u{00FA}"],
            'cjk' => ['今日のワークアウト：スクワット 3×10'],
        ];
    }

    // ---- invalid and missing metadata ------------------------------------------------------------------------------------

    private function valid(): array
    {
        return $this->stored('Coach words.', [NoticeCode::SAVE_FAILED => 'App words.'])->meta;
    }

    private function content(): string
    {
        return "Coach words.\n\nApp words.";
    }

    /** @return array<string, array{0: callable(array): array}> */
    public static function malformed(): array
    {
        $segment = fn (array $m, array $change) => array_replace_recursive($m, [ReplyText::META_KEY => ['segments' => [$change]]]);

        return [
            'wrong version' => [fn (array $m) => array_replace_recursive($m, [ReplyText::META_KEY => ['v' => 2]])],
            'no version' => [function (array $m) {
                unset($m[ReplyText::META_KEY]['v']);

                return $m;
            }],
            'wrong length' => [fn (array $m) => array_replace_recursive($m, [ReplyText::META_KEY => ['length' => 999]])],
            'hash of other content' => [fn (array $m) => array_replace_recursive($m, [ReplyText::META_KEY => ['sha256' => hash('sha256', 'other')]])],
            'no hash' => [function (array $m) {
                unset($m[ReplyText::META_KEY]['sha256']);

                return $m;
            }],
            'end beyond the text' => [fn (array $m) => $segment($m, ['end' => 9999])],
            'start negative' => [fn (array $m) => $segment($m, ['start' => -1])],
            'empty segment' => [fn (array $m) => $segment($m, ['start' => 14, 'end' => 14])],
            'start after end' => [fn (array $m) => $segment($m, ['start' => 20, 'end' => 14])],
            'start as a string' => [fn (array $m) => $segment($m, ['start' => '12'])],
            'end as null' => [fn (array $m) => $segment($m, ['end' => null])],
            'code with capitals' => [fn (array $m) => $segment($m, ['code' => 'Save_Failed'])],
            'code with spaces' => [fn (array $m) => $segment($m, ['code' => 'save failed'])],
            'code not a string' => [fn (array $m) => $segment($m, ['code' => 7])],
            'code too long' => [fn (array $m) => $segment($m, ['code' => str_repeat('a', 65)])],
            'no segments' => [fn (array $m) => [ReplyText::META_KEY => ['segments' => []] + $m[ReplyText::META_KEY]]],
            'segments not a list' => [fn (array $m) => [ReplyText::META_KEY => ['segments' => ['a' => $m[ReplyText::META_KEY]['segments'][0]]] + $m[ReplyText::META_KEY]]],
            'record is a string' => [fn (array $m) => [ReplyText::META_KEY => 'application']],
            'record is null' => [fn (array $m) => [ReplyText::META_KEY => null]],
            'record is a number' => [fn (array $m) => [ReplyText::META_KEY => 1]],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_record_that_does_not_verify_fails_closed_and_nothing_is_read_as_the_coachs_words(callable $break): void
    {
        $meta = $break($this->valid());

        $this->assertSame('', ApplicationNotices::modelText($this->assistant($this->content(), $meta)));
    }

    public function test_overlapping_and_unsorted_segments_fail_closed(): void
    {
        $content = "Coach.\n\nOne.\n\nTwo.";
        $record = [
            'v' => 1, 'length' => mb_strlen($content), 'sha256' => hash('sha256', $content),
        ];

        $overlapping = $record + ['segments' => [['code' => 'a', 'start' => 6, 'end' => 14], ['code' => 'b', 'start' => 10, 'end' => mb_strlen($content)]]];
        $unsorted = $record + ['segments' => [['code' => 'b', 'start' => 14, 'end' => mb_strlen($content)], ['code' => 'a', 'start' => 6, 'end' => 14]]];
        $sound = $record + ['segments' => [['code' => 'a', 'start' => 6, 'end' => 14], ['code' => 'b', 'start' => 14, 'end' => mb_strlen($content)]]];

        $this->assertSame('', ApplicationNotices::modelText($this->assistant($content, [ReplyText::META_KEY => $overlapping])));
        $this->assertSame('', ApplicationNotices::modelText($this->assistant($content, [ReplyText::META_KEY => $unsorted])));
        $this->assertSame('Coach.', ApplicationNotices::modelText($this->assistant($content, [ReplyText::META_KEY => $sound])), 'Control: the same record, well formed, verifies.');
    }

    public function test_too_many_segments_fail_closed(): void
    {
        $model = 'Coach.';
        $reply = ReplyText::fromModel($model);

        for ($i = 0; $i < 17; $i++) {
            $reply->with(NoticeCode::RANGE_NOTE, "Note {$i}.");
        }

        $this->assertSame('', ApplicationNotices::modelText($this->assistant($reply->text(), $reply->meta())));
    }

    public function test_a_record_copied_to_another_message_or_kept_after_an_edit_no_longer_verifies(): void
    {
        $meta = $this->valid();

        $this->assertSame('', ApplicationNotices::modelText($this->assistant("Another reply entirely.\n\nWith a notice.", $meta)), 'Copied to other content.');
        $this->assertSame('', ApplicationNotices::modelText($this->assistant($this->content().' (edited)', $meta)), 'Content changed after it was recorded.');
        $this->assertSame('Coach words.', ApplicationNotices::modelText($this->assistant($this->content(), $meta)), 'Control: the original.');
    }

    public function test_content_that_is_not_valid_utf8_fails_closed_when_a_record_exists(): void
    {
        $content = "Coach \xB1\xB2 words.\n\nApp words.";
        $record = ['v' => 1, 'length' => 10, 'sha256' => hash('sha256', $content), 'segments' => [['code' => 'a', 'start' => 6, 'end' => 10]]];

        $this->assertSame('', ApplicationNotices::modelText($this->assistant($content, [ReplyText::META_KEY => $record])));
    }

    public function test_a_code_this_version_does_not_know_still_marks_a_notice(): void
    {
        $message = $this->stored('Coach.', ['some_future_notice' => 'App words.']);

        $this->assertSame('Coach.', ApplicationNotices::modelText($message), 'Only the shape of a code matters, so a newer release can add codes and an older one still skips them.');
    }

    public function test_a_users_own_message_is_never_filtered_and_cannot_carry_notices(): void
    {
        $meta = $this->valid();
        $forged = new Message(['role' => Message::ROLE_USER, 'content' => $this->content(), 'meta' => $meta]);

        $this->assertSame($this->content(), ApplicationNotices::modelText($forged));
    }

    public function test_metadata_can_only_narrow_what_is_read_never_add_to_it(): void
    {
        $content = "Coach says: squats 3x10.\n\nApp says: push-ups 3x12.";
        $message = $this->assistant($content, [ReplyText::META_KEY => $this->stored('Coach says: squats 3x10.', [NoticeCode::SAVE_FAILED => 'App says: push-ups 3x12.'])->meta[ReplyText::META_KEY], 'authorization' => 'plan_create', 'allow' => ['create_workout_plan']]);

        $text = ApplicationNotices::modelText($message);

        $this->assertTrue(str_contains($content, $text), 'What is returned is always a part of what was stored.');
        $this->assertStringNotContainsString('push-ups', $text);
    }

    // ---- historical messages (no record) -----------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function legacyNotices(): array
    {
        return [
            'save failed / held / retry' => ["I haven't changed your plan: the workout was not saved. Ask me again with \"add this workout to my dashboard\"."],
            'held replacement question' => ["I haven't changed your plan yet. These days already have a plan:\n- 2026-11-04: “Old plan” (Squats 3×10) would be replaced with “New” (Push-ups 3×12)"],
            'nothing pending' => ['There is nothing waiting for your confirmation (it may have expired). To put the workout we just discussed on your plan, say "add this workout to my dashboard".'],
            'unset load' => ['I left the starting weight for Goblet Squat unset: there is no recorded or planned weight to base one on.'],
            'replaced as asked' => ['As you asked, I replaced “Old plan” (Squats 3×10) on 2026-11-04 with this workout.'],
            'plan changed since' => ["Your plan changed since I asked, so I didn't make that change. To change your plan, send an explicit request."],
            'range explanation' => ['Your plan keeps one number for each of these, so I saved the low end of every range: Goblet Squat 8 reps (written 8-12). You can change them in the plan.'],
            'to be clear' => ["To be clear, that is not a limit of the app: I can add and change workouts on your plan whenever you ask. I haven't changed anything this time."],
            'confirmed change failed' => ["I couldn't make the change exactly as you confirmed it, so nothing was changed. If you still want it, ask again."],
            'everything in that plan' => ['Everything in that plan (2026-11-04) is already on your plan exactly as shown, so there was nothing to change.'],
        ];
    }

    #[DataProvider('legacyNotices')]
    public function test_each_known_english_notice_without_a_record_is_cut_off_the_coachs_words(string $notice): void
    {
        $coach = "Here is a plan:\n1. Squats 3x10";
        $message = $this->assistant($coach."\n\n".$notice."\n1. Burpees 5x20", null);

        $this->assertSame($coach, ApplicationNotices::modelText($message));
    }

    public function test_the_earliest_of_several_historical_notices_decides_where_the_cut_is(): void
    {
        $message = $this->assistant("Coach.\n\nI left the starting weight for X unset.\n\nI haven't changed your plan.", null);

        $this->assertSame('Coach.', ApplicationNotices::modelText($message));
    }

    public function test_a_historical_reply_with_no_notice_is_the_coachs_whole_reply(): void
    {
        $content = "Try this:\n1. Squats 3x10\n2. Rows 3x10\n\nWould you like me to add this to your dashboard?";

        $this->assertSame($content, ApplicationNotices::modelText($this->assistant($content, null)));
        $this->assertSame($content, ApplicationNotices::modelText($this->assistant($content, ['tool_calls' => []])), 'Other metadata is not a notice record.');
    }

    public function test_a_confirmation_result_stored_before_records_existed_is_wholly_the_applications(): void
    {
        $content = 'Done. I replaced “Old plan” (Squats 3×10) on 2026-11-04 with “Upper body” (Push-ups 3×12). I checked that it is saved.';

        $this->assertSame('', ApplicationNotices::modelText($this->assistant($content, ['plan_replacement' => 'applied'])));
        $this->assertSame('', ApplicationNotices::modelText($this->assistant($content, ['plan_replacement' => 'refused'])));
    }

    public function test_an_application_sentence_not_after_a_blank_line_is_not_guessed_at(): void
    {
        $content = "I haven't changed your plan, the coach says, but here is a workout:\n1. Squats 3x10";

        $this->assertSame($content, ApplicationNotices::modelText($this->assistant($content, null)), 'The historical rule is exactly "a blank line, then one of the application sentences".');
    }

    public function test_the_language_of_a_notice_never_decides_whether_it_is_one(): void
    {
        $spanish = $this->stored('Aquí tienes:', [NoticeCode::SAVE_FAILED => "No he cambiado tu plan: no se guardó el entrenamiento.\n1. Sentadillas 3x10"]);
        $english = $this->stored('Here you go:', [NoticeCode::SAVE_FAILED => "I haven't changed your plan: the workout was not saved.\n1. Squats 3x10"]);
        $mixed = $this->stored('Here you go / Aquí tienes:', [NoticeCode::SAVE_FAILED => "No he cambiado tu plan. I haven't changed your plan.\n1. Squats 3x10"]);

        $this->assertSame('Aquí tienes:', ApplicationNotices::modelText($spanish));
        $this->assertSame('Here you go:', ApplicationNotices::modelText($english));
        $this->assertSame('Here you go / Aquí tienes:', ApplicationNotices::modelText($mixed));
    }
}
