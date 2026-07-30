<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $profile
 * @property string $category
 * @property Carbon $sequence_date
 * @property int $last_sequence
 */
class DmsTicketSequence extends Model
{
    protected $table = 'survey_trigger_dms_ticket_sequences';

    protected $fillable = [
        'profile',
        'category',
        'sequence_date',
        'last_sequence',
    ];

    protected function casts(): array
    {
        return [
            'sequence_date' => 'date',
            'last_sequence' => 'integer',
        ];
    }
}
