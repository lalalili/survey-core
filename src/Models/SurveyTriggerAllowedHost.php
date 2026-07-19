<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Lalalili\AudienceCore\Concerns\LogsModelActivity;

/**
 * @property int $id
 * @property string $host
 * @property string|null $description
 */
class SurveyTriggerAllowedHost extends Model
{
    use LogsModelActivity;

    protected $fillable = ['host', 'description'];
}
