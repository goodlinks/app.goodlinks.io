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

    /**
     * @param $historyItemId
     * @return HistoryItemProject
     */
    public static function findByHistoryItemId($historyItemId)
    {
        $first = $model = static::where('history_item_id', '=', $historyItemId)
            ->first();

        if (!is_null($first)) {
            return $first;
        }

        return new static;
    }
}