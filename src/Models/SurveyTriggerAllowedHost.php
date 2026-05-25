<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $host
 * @property string|null $description
 */
class SurveyTriggerAllowedHost extends Model
{
    protected $fillable = ['host', 'description'];
}
