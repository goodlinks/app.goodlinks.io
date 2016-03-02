<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class HistoryItem
 * @package App\Model
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class HistoryItem extends Model
{
    protected $guarded = [];

    public function getId()
    {
        return $this->id;
    }

    public function getBuzzstreamCreatedAt()
    {
        return $this->buzzstream_created_at;
    }

    public function getBuzzstreamId()
    {
        return $this->buzzstream_id;
    }

    public function getBuzzstreamApiUrl()
    {
        return "https://api.buzzstream.com/v1/history/" . $this->getBuzzstreamId();
    }

    /**
     * @param $buzzstreamId
     * @return HistoryItem
     */
    public static function findByBuzzstreamId($buzzstreamId)
    {
        $first = $model = static::where('buzzstream_id', '=', $buzzstreamId)
            ->first();

        if (!is_null($first)) {
            return $first;
        }

        return new static;
    }
}