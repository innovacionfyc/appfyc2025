<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PodcastReaccion extends Model
{
    public const ME_GUSTA = 'me_gusta';

    protected $table = 'podcast_reacciones';

    protected $fillable = [
        'episodio_id',
        'tipo',
        'fingerprint',
    ];

    public function episodio()
    {
        return $this->belongsTo(PodcastEpisodio::class, 'episodio_id');
    }
}
