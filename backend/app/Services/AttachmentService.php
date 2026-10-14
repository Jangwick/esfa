<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    public function store(UploadedFile $file, Model $owner, ?User $user = null): Attachment
    {
        $allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if ($file->getSize() > 4 * 1024 * 1024 || ! in_array($file->getMimeType(), $allowed, true)) { throw ValidationException::withMessages(['attachments' => ['Use PDF, PNG, JPG, WEBP, TXT or CSV files up to 4 MB.']]); }
        $values = ['filename' => mb_strcut(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255, 'UTF-8'), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'content' => base64_encode(file_get_contents($file->getRealPath())), 'uploaded_by' => $user?->id, 'attachable_type' => get_class($owner), 'attachable_id' => $owner->getKey()];
        if ($owner instanceof TicketMessage) { $values += ['ticket_id' => $owner->ticket_id, 'ticket_message_id' => $owner->id]; }

        return Attachment::create($values);
    }

    public function download(Attachment $attachment)
    {
        $content = base64_decode($attachment->content, true);
        abort_if($content === false, 404);

        return response($content, 200, ['Content-Type' => $attachment->mime_type, 'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '', $attachment->filename).'"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
