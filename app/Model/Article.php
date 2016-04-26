<?php

namespace App\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Article
 * @package App\Model
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class Article extends Model
{
    protected $guarded = [];

    public function getId()
    {
        return $this->id;
    }

    public function getBilledAt()
    {
        return $this->billed_at;
    }

    public function getBilledAtDate()
    {
        $date = new Carbon($this->getBilledAt());
        return $date;
    }

    public function getBuzzstreamProjectId()
    {
        return $this->buzzstream_project_id;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getWordCount()
    {
        return $this->word_count;
    }
}