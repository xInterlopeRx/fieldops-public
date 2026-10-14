<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharePointAttachment extends Model
{
    protected $table = 'sharepoint_attachments';

    protected $fillable = [
        'company_id',
        'site_id',
        'project_id',
        'job_id',
        'uploaded_by',
        'filename',
        'mime_type',
        'file_size',
        'sharepoint_drive_id',
        'sharepoint_item_id',
        'sharepoint_url',
        'client_upload_id',
    ];

    protected $hidden = [
        'client_upload_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'company_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
