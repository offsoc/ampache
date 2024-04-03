<?php

declare(strict_types=0);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Ampache.org, 2001-2023
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Ampache\Repository\Model;

use Ampache\Module\Playback\Stream;
use Ampache\Module\Playback\Stream_Url;
use Ampache\Module\System\Dba;
use Ampache\Config\AmpConfig;
use Ampache\Module\System\Core;
use Ampache\Module\System\Plugin\PluginTypeEnum;
use Ampache\Module\Wanted\MissingArtistRetrieverInterface;

class Song_Preview extends database_object implements Media, playable_item
{
    protected const DB_TABLENAME = 'song_preview';

    public int $id = 0;

    public ?string $session = null;

    // artist.id (Int)
    public ?int $artist         = null;
    public ?string $artist_mbid = null;

    public ?string $title = null;

    public ?string $album_mbid = null;

    //  // MusicBrainz ID
    public ?string $mbid = null;
    public ?int $disk    = null;

    public ?int $track = null;

    public ?string $file = null;

    public ?string $link = null;

    public $enabled      = true;

    public $mime;

    public $type;

    public $f_artist;

    public $f_artist_link;

    public $f_name;

    public $f_name_full;

    public $f_link;

    public $f_album_link;

    public $f_album;

    public $f_track;

    /**
     * Constructor
     *
     * Song Preview class
     * @param int|null $object_id
     */
    public function __construct($object_id = 0)
    {
        if (!$object_id) {
            return;
        }

        $info = $this->has_info($object_id);
        if ($info === []) {
            return;
        }

        foreach ($info as $key => $value) {
            $this->$key = $value;
        }

        $this->id = (int)$object_id;
        if ($this->file) {
            $data       = pathinfo($this->file);
            $this->type = (isset($data['extension']))
                ? strtolower($data['extension'])
                : 'mp3';
            $this->mime = Song::type_to_mime($this->type);
        }
    }

    public function getId(): int
    {
        return (int)($this->id ?? 0);
    }

    public function isNew(): bool
    {
        return $this->getId() === 0;
    }

    /**
     * insert
     *
     * This inserts the song preview described by the passed array
     * @param array $results
     */
    public static function insert($results): ?int
    {
        if ((int)$results['disk'] == 0) {
            $results['disk'] = Album::sanitize_disk($results['disk']);
        }

        if ((int)$results['track'] == 0) {
            $results['disk']  = Album::sanitize_disk($results['track'][0]);
            $results['track'] = substr((string) $results['track'], 1);
        }

        $sql = 'INSERT INTO `song_preview` (`file`, `album_mbid`, `artist`, `artist_mbid`, `title`, `disk`, `track`, `mbid`, `session`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $db_results = Dba::write($sql, [$results['file'], $results['album_mbid'], $results['artist'], $results['artist_mbid'], $results['title'], $results['disk'], $results['track'], $results['mbid'], $results['session']]);

        if (!$db_results) {
            debug_event(self::class, 'Unable to insert ' . $results['disk'] . '-' . $results['track'] . '-' . $results['title'], 2);

            return null;
        }

        $preview_id = Dba::insert_id();
        if (!$preview_id) {
            return null;
        }

        return (int)$preview_id;
    }

    /**
     * build_cache
     *
     * This attempts to reduce queries by asking for everything in the
     * browse all at once and storing it in the cache, this can help if the
     * db connection is the slow point.
     * @param array $song_ids
     */
    public static function build_cache($song_ids): bool
    {
        if (empty($song_ids)) {
            return false;
        }

        $idlist = '(' . implode(',', $song_ids) . ')';
        if ($idlist == '()') {
            return false;
        }

        // Song data cache
        $sql        = 'SELECT `id`, `file`, `album_mbid`, `artist`, `artist_mbid`, `title`, `disk`, `track`, `mbid` FROM `song_preview` WHERE `id` IN ' . $idlist;
        $db_results = Dba::read($sql);

        $artists = [];
        while ($row = Dba::fetch_assoc($db_results)) {
            parent::add_to_cache('song_preview', $row['id'], $row);
            if ($row['artist']) {
                $artists[$row['artist']] = $row['artist'];
            }
        }

        Artist::build_cache($artists);

        return true;
    }

    /**
     * has_info
     * @param int|null $preview_id
     */
    private function has_info($preview_id = 0): array
    {
        if ($preview_id === null) {
            return [];
        }

        if (parent::is_cached('song_preview', $preview_id)) {
            return parent::get_from_cache('song_preview', $preview_id);
        }

        $sql        = 'SELECT `id`, `file`, `album_mbid`, `artist`, `artist_mbid`, `title`, `disk`, `track`, `mbid` FROM `song_preview` WHERE `id` = ?';
        $db_results = Dba::read($sql, [$preview_id]);

        $results = Dba::fetch_assoc($db_results);
        if (!empty($results['id'])) {
            if (empty($results['artist_mbid'])) {
                $sql        = 'SELECT `mbid` FROM `artist` WHERE `id` = ?';
                $db_results = Dba::read($sql, [$results['artist']]);
                if ($artist_res = Dba::fetch_assoc($db_results)) {
                    $results['artist_mbid'] = $artist_res['mbid'];
                }
            }

            parent::add_to_cache('song_preview', $preview_id, $results);

            return $results;
        }

        return [];
    }

    /**
     * get_artist_fullname
     * gets the name of $this->artist, allows passing of id
     */
    public function get_artist_fullname(): string
    {
        if ($this->artist) {
            return (string) (new Artist($this->artist))->get_fullname();
        } else {
            $wartist = $this->getMissingArtistRetriever()->retrieve((string) $this->artist_mbid);

            return $wartist['name'] ?? '';
        }
    }

    /**
     * format
     * This takes the current song object
     * and does a ton of formatting on it creating f_??? variables on the current
     * object
     *
     * @param bool $details
     */
    public function format($details = true): void
    {
        unset($details); // dead code but called from other format calls
        // Format the artist name
        $this->f_artist = $this->get_artist_fullname();

        if ($this->artist) {
            $this->f_artist_link = "<a href=\"" . AmpConfig::get('web_path') . "/artists.php?action=show&amp;artist=" . $this->artist . "\" title=\"" . scrub_out($this->f_artist) . "\"> " . scrub_out($this->f_artist) . "</a>";
        } else {
            $wartist             = $this->getMissingArtistRetriever()->retrieve((string) $this->artist_mbid);
            $this->f_artist_link = $wartist['link'] ?? '';
        }

        // Format the title
        $this->f_name_full  = $this->title;
        $this->f_name       = $this->title;
        $this->f_album_link = "<a href=\"" . AmpConfig::get('web_path') . "/albums.php?action=show_missing&amp;mbid=" . $this->album_mbid . "&amp;artist=" . $this->artist . "\" title=\"" . $this->f_album . "\">" . $this->f_album . "</a>";
        $this->get_f_link();

        // Format the track (there isn't really anything to do here)
        $this->f_track = $this->track;
    }

    /**
     * Get item fullname.
     */
    public function get_fullname(): ?string
    {
        if ($this->f_name === null) {
            $this->f_name = $this->title;
        }

        return $this->f_name;
    }

    /**
     * Get item link.
     */
    public function get_link(): string
    {
        // don't do anything if it's formatted
        if ($this->link === null) {
            $this->link = "#";
        }

        return $this->link;
    }

    /**
     * Get item f_link.
     */
    public function get_f_link(): string
    {
        // don't do anything if it's formatted
        if ($this->f_link === null) {
            $this->f_link = "<a href=\"" . scrub_out($this->get_link()) . "\" title=\"" . scrub_out($this->f_artist) . " - " . scrub_out($this->title) . "\"> " . scrub_out($this->f_name) . "</a>";
        }

        return $this->f_link;
    }

    /**
     * get_parent
     * Return parent `object_type`, `object_id`; null otherwise.
     */
    public function get_parent(): ?array
    {
        // Wanted album is not part of the library, cannot return it.
        return null;
    }

    public function get_childrens(): array
    {
        return [];
    }

    /**
     * Search for direct children of an object
     * @param string $name
     */
    public function get_children($name): array
    {
        debug_event(self::class, 'get_children ' . $name, 5);

        return [];
    }

    /**
     * @return list<array{object_type: LibraryItemEnum, object_id: int}>
     */
    public function get_medias(?string $filter_type = null): array
    {
        $medias = [];
        if ($filter_type === null || $filter_type === 'song_preview') {
            $medias[] = ['object_type' => LibraryItemEnum::SONG_PREVIEW, 'object_id' => $this->id];
        }

        return $medias;
    }

    /**
     * play_url
     * This function takes all the song information and correctly formats a
     * stream URL taking into account the downsampling mojo and everything
     * else, this is the true function
     * @param string $additional_params
     * @param string $player
     * @param bool $local
     */
    public function play_url($additional_params = '', $player = '', $local = false): string
    {
        $user_id = (Core::get_global('user') instanceof User)
            ? (string)Core::get_global('user')->getId()
            : '-1';
        $type      = $this->type;
        $song_name = rawurlencode($this->get_artist_fullname() . " - " . $this->title . "." . $type);
        $url       = Stream::get_base_url($local) . "type=song_preview&oid=" . $this->id . "&uid=" . $user_id . "&name=" . $song_name;

        return Stream_Url::format($url . $additional_params);
    }

    /**
     * stream
     */
    public function stream(): void
    {
        $user = Core::get_global('user');
        if (!$user instanceof User) {
            return;
        }

        foreach (Plugin::get_plugins(PluginTypeEnum::SONG_PREVIEW_STREAM_PROVIDER) as $plugin_name) {
            $plugin = new Plugin($plugin_name);
            if ($plugin->_plugin !== null && $plugin->load($user) && $plugin->_plugin->stream_song_preview($this->file)) {
                break;
            }
        }
    }

    /**
     * get_stream_types
     * @param $player
     */
    public function get_stream_types($player = null): array
    {
        return ['native'];
    }

    /**
     * get_stream_name
     */
    public function get_stream_name(): string
    {
        return (string)$this->title;
    }

    /**
     * get_transcode_settings
     *
     * FIXME: Song Preview transcoding is not implemented
     * @param string $target
     * @param string $player
     * @param array $options
     */
    public function get_transcode_settings($target = null, $player = null, $options = []): array
    {
        return [];
    }

    /**
     * getYear
     */
    public function getYear(): string
    {
        return '';
    }

    /**
     * @param int $user_id
     * @param string $agent
     * @param array $location
     * @param int $date
     */
    public function set_played($user_id, $agent, $location, $date): bool
    {
        // Do nothing
        unset($user_id, $agent, $location, $date);

        return false;
    }

    /**
     * @param int $user
     * @param string $agent
     * @param int $date
     */
    public function check_play_history($user, $agent, $date): bool
    {
        // Do nothing
        unset($user, $agent, $date);

        return false;
    }

    /**
     * @param string $album_mbid
     */
    public static function get_song_previews($album_mbid): array
    {
        $songs = [];

        $sql        = "SELECT `id` FROM `song_preview` WHERE `session` = ? AND `album_mbid` = ?";
        $db_results = Dba::read($sql, [session_id(), $album_mbid]);

        while ($results = Dba::fetch_assoc($db_results)) {
            $songs[] = new Song_Preview($results['id']);
        }

        return $songs;
    }

    public function has_art(): bool
    {
        return false;
    }

    public function get_user_owner(): ?int
    {
        return null;
    }

    public function get_description(): string
    {
        return '';
    }

    /**
     * garbage_collection
     */
    public static function garbage_collection(): void
    {
        $sql = 'DELETE FROM `song_preview` USING `song_preview` LEFT JOIN `session` ON `session`.`id`=`song_preview`.`session` WHERE `session`.`id` IS NULL';
        Dba::write($sql);
    }

    public function remove(): bool
    {
        return true;
    }

    /**
     * Returns the filename of the media-item
     */
    public function getFileName(): string
    {
        return '';
    }

    public function getMediaType(): LibraryItemEnum
    {
        return LibraryItemEnum::SONG_PREVIEW;
    }

    /**
     * @deprecated inject dependency
     */
    private function getMissingArtistRetriever(): MissingArtistRetrieverInterface
    {
        global $dic;

        return $dic->get(MissingArtistRetrieverInterface::class);
    }
}
