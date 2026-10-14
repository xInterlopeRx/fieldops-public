<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Services\MicrosoftGraphApiClient;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('graph:mail:test {recipient : An explicitly allowlisted local developer mailbox} {--confirm-recipient= : Must exactly repeat the recipient to authorize this one test send}')]
#[Description('Send one minimal Graph delivery test to an allowlisted developer mailbox in local only.')]
class SendGraphMailTest extends Command
{
    public function handle(MicrosoftGraphApiClient $graph, MicrosoftGraphRuntimeTargetResolver $runtimeTarget): int
    {
        if (! app()->isLocal()) {
            $this->error('Graph mail test sends are available only when APP_ENV=local.');

            return self::FAILURE;
        }

        if (! config('services.microsoft_graph.writes_enabled')) {
            $this->error('Microsoft Graph notification writes are disabled.');

            return self::FAILURE;
        }

        $recipient = strtolower(trim((string) $this->argument('recipient')));

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Supply one valid developer test email address.');

            return self::FAILURE;
        }

        $allowedRecipients = config('services.microsoft_graph.test_recipients', []);
        $allowedRecipients = is_array($allowedRecipients)
            ? array_map(static fn (mixed $address): string => strtolower(trim((string) $address)), $allowedRecipients)
            : [];

        if (! in_array($recipient, $allowedRecipients, true)) {
            $this->error('This recipient is not in MICROSOFT_GRAPH_TEST_RECIPIENTS.');

            return self::FAILURE;
        }

        $confirmation = strtolower(trim((string) $this->option('confirm-recipient')));

        if (! hash_equals($recipient, $confirmation)) {
            $this->error('Repeat the exact recipient with --confirm-recipient to send this one test message.');

            return self::FAILURE;
        }

        $mailboxId = $runtimeTarget->notificationMailboxId();

        if (! is_string($mailboxId) || trim($mailboxId) === '') {
            $this->error('The Graph notification mailbox is not configured.');

            return self::FAILURE;
        }

        $testId = (string) Str::uuid();

        try {
            $graph->postJson('users/'.rawurlencode(trim($mailboxId)).'/sendMail', [
                'message' => [
                    'subject' => 'FieldOps Graph delivery test '.$testId,
                    'body' => [
                        'contentType' => 'text',
                        'content' => "This is a manually triggered FieldOps development delivery test.\nNo customer or Job data is included.\nTest ID: {$testId}",
                    ],
                    'toRecipients' => [[
                        'emailAddress' => ['address' => $recipient],
                    ]],
                ],
                'saveToSentItems' => false,
            ]);
        } catch (MicrosoftGraphApiException $exception) {
            $this->error('Graph test email failed: '.$exception->reason.'.');

            if ($exception->reason === 'graph_unavailable') {
                $this->warn('The send outcome may be unknown. Check Exchange message trace before trying again.');
            }

            return self::FAILURE;
        }

        $this->info('Graph accepted one test email for '.$recipient.'.');
        $this->comment('Accepted by Graph does not confirm delivery. Use the read-only Exchange message-trace preflight to check delivery.');

        return self::SUCCESS;
    }
}
