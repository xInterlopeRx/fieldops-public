<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SharePointAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RetireSharePointAttachmentPointers
{
    /** @param list<int> $attachmentIds */
    public function retire(User $actor, array $attachmentIds, string $adminUpn, string $verificationReference): int
    {
        return DB::transaction(function () use ($actor, $attachmentIds, $adminUpn, $verificationReference): int {
            $attachments = SharePointAttachment::query()
                ->whereKey($attachmentIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($attachments->count() !== count($attachmentIds)) {
                throw new ConflictHttpException('One or more selected SharePoint pointers have already changed. Refresh the page and review the remaining pointers.');
            }

            foreach ($attachments as $attachment) {
                AuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'subject_type' => $attachment->getMorphClass(),
                    'subject_id' => $attachment->getKey(),
                    'event' => 'sharepoint_attachment_pointer_retired',
                    'summary' => $actor->name.' retired local SharePoint metadata pointer #'.$attachment->getKey().'; the remote file was not changed.',
                    'metadata' => [
                        'local_pointer_only' => true,
                        'sharepoint_api_called' => false,
                        'remote_file_deleted' => false,
                        'sharepoint_admin_upn' => strtolower(trim($adminUpn)),
                        'verification_reference' => trim($verificationReference),
                    ],
                    'created_at' => now(),
                ]);

                $attachment->delete();
            }

            return $attachments->count();
        });
    }
}
