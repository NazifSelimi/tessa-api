<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** Statuses that mean "not delivered (yet)". */
    public const NOT_SENT = [self::STATUS_QUEUED, self::STATUS_RETRYING, self::STATUS_FAILED];

    protected $fillable = [
        'type', 'to_email', 'to_name', 'subject', 'status', 'delivery', 'redirected_to', 'error', 'attempts',
        'payload', 'order_id', 'user_id', 'campaign_id', 'created_by', 'sent_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }
}
