<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class HistoryItemProject
 * @package App\Model
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class HistoryItemProject extends Model
{
    protected $guarded = [];

    public function getId()
    {
        return $this->id;
    }

    public function getBuzzstreamProjectId()
    {
        return $this->buzzstream_project_id;
    }
}