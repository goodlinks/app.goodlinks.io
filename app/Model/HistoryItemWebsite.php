<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class HistoryItemWebsite
 * @package App\Model
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class HistoryItemWebsite extends Model
{
    protected $guarded = [];

    public function getId()
    {
        return $this->id;
    }

    public function getBuzzstreamWebsiteId()
    {
        return $this->buzzstream_website_id;
    }
}