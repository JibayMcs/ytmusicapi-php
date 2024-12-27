<?php

// 388

namespace Ytmusicapi;

use ytmusicapi\Playlist;

trait Playlists
{
    /**
     * Returns information and tracks of a playlist.
     *
     * Known differences from Python version:
     *   - Returns an empty array instead of null for missing artists
     *   - Additional $get_continuations parameter for pagnating results
     *   - Liked music playlist is PRIVATE instead of PUBLIC
     *
     * @param string $playlistId Playlist ID
     * @param int $limit Maximum number of tracks to return (This isn't quite accurate as continuations can cause this to be exceded)
     * @param bool $related Whether to return related playlists
     * @param int $suggestions_limit Maximum number of suggestions to return
     * @param bool $get_continuations Whether to return continuations. When set to false, only the first 100 or so tracks
     *   will be returned, and a token will be provided in the continuation property to get the next set of tracks.
     *   (PHP Only, not in Python version. Setting this to false is useful for making multiple requests to get all
     *   tracks if you want to provide some sort of progress indicator, otherwise, leave it as true.)
     * @return Playlist
     */
    public function get_playlist($playlistId, $limit = 100, $related = false, $suggestions_limit = 0, $get_continuations = true)
    {
        $browseId = str_starts_with($playlistId, "VL") ? $playlistId : "VL" . $playlistId;
        $body = ["browseId" => $browseId, "params" => "wgYCCAE%3D"];
        $endpoint = "browse";
        $response = $this->_send_request($endpoint, $body);

        $header_data = nav($response, join(TWO_COLUMN_RENDERER, TAB_CONTENT, SECTION_LIST_ITEM));
        $section_list = nav($response, join(TWO_COLUMN_RENDERER, "secondaryContents", SECTION));
        $playlist = [];

        $playlist["owned"] = !empty($header_data->musicEditablePlaylistDetailHeaderRenderer->editHeader);

        if (!$playlist["owned"]) {
            $header = nav($header_data, RESPONSIVE_HEADER);
            $playlist["id"] = nav(
                $header,
                join("buttons", 1, "musicPlayButtonRenderer", "playNavigationEndpoint", WATCH_PLAYLIST_ID),
                true
            );
            $playlist["privacy"] = "PUBLIC";
        } else {
            $playlist["id"] = nav($header_data, join(EDITABLE_PLAYLIST_DETAIL_HEADER, PLAYLIST_ID));
            $header = nav($header_data, join(EDITABLE_PLAYLIST_DETAIL_HEADER, HEADER, RESPONSIVE_HEADER));
            $playlist["privacy"] = nav($header_data, join(EDITABLE_PLAYLIST_DETAIL_HEADER, "editHeader", "musicPlaylistEditHeaderRenderer", "privacy"), true);
        }

        // [PHP Only] Attempt at getting author
        $author = nav($header_data, join(RESPONSIVE_HEADER, "facepile.avatarStackViewModel"), true);
        if ($author) {
            $playlist["author"] = (object)[
                "name" => $author->text->content,
                "id" => nav($author, "rendererContext.commandContext.onTap.innertubeCommand.browseEndpoint.browseId", true),
            ];
        } else {
            $playlist["author"] = null;
        }

        // [PHP Only] Attempt at getting thumbnails
        $playlist["thumbnails"] = nav($header_data, join(RESPONSIVE_HEADER, THUMBNAILS), true);

        $description_shelf = nav($header, join("description", DESCRIPTION_SHELF), true);
        $playlist["description"] = $description_shelf
            ? implode("", array_column($description_shelf->description->runs, "text"))
            : null;
        $playlist["thumbnails"] = nav($header, THUMBNAILS);
        $playlist["title"] = nav($header, TITLE_TEXT);
        $playlist = array_merge($playlist, parse_song_runs(array_slice(nav($header, SUBTITLE_RUNS), 2 + ($playlist["owned"] ? 2 : 0))));

        $playlist["views"] = null;
        $playlist["duration"] = null;
        if (isset($header->secondSubtitle->runs)) {
            $second_subtitle_runs = $header->secondSubtitle->runs;
            $has_views = (count($second_subtitle_runs) > 3) * 2;
            $playlist["views"] = !$has_views ? null : (int)($second_subtitle_runs[0]->text);
            $has_duration = (count($second_subtitle_runs) > 1) * 2;
            $playlist["duration"] = !$has_duration ? null : $second_subtitle_runs[$has_views + $has_duration]->text;

            $song_count_text = $second_subtitle_runs[$has_views + 0]->text;

            $matches = [];
            if (preg_match("/\d+/", $song_count_text, $matches)) {
                $song_count_search = $matches[0];
                $song_count = (int)$song_count_search;
            } else {
                $song_count = 0;
            }
        } else {
            $song_count = count($section_list->contents);
        }

        $playlist["trackCount"] = $song_count;

        $request_func = function($additionalParams) use ($endpoint, $body) {
            return $this->_send_request($endpoint, $body, $additionalParams);
        };

        $playlist["related"] = [];
        if (isset($section_list->continuations) && $get_continuations) {
            $additionalParams = get_continuation_params($section_list);
            if ($playlist["owned"] && ($suggestions_limit > 0 || $related)) {
                $parse_func = function($results) {
                    return parse_playlist_items($results);
                };
                $suggested = $request_func($additionalParams);
                $continuation = nav($suggested, SECTION_LIST_CONTINUATION);
                $additionalParams = get_continuation_params($continuation);
                $suggestions_shelf = nav($continuation, join(CONTENT, MUSIC_SHELF));
                $playlist["suggestions"] = get_continuation_contents($suggestions_shelf, $parse_func);

                $playlist["suggestions"] = array_merge(
                    $playlist["suggestions"],
                    get_continuations(
                        $suggestions_shelf,
                        "musicShelfContinuation",
                        $suggestions_limit - count($playlist["suggestions"]),
                        $request_func,
                        $parse_func,
                        true
                    )
                );
            }

            if ($related) {
                $response = $request_func($additionalParams);
                $continuation = nav($response, SECTION_LIST_CONTINUATION, true);
                if ($continuation) {
                    $parse_func = function($results) {
                        return parse_content_list($results, 'Ytmusicapi\\parse_playlist');
                    };
                    $playlist["related"] = get_continuation_contents(
                        nav($continuation, join(CONTENT, CAROUSEL)),
                        $parse_func
                    );
                }
            }
        }

        $playlist["tracks"] = [];
        $content_data = nav($section_list, join(CONTENT, "musicPlaylistShelfRenderer"));
        if (isset($content_data->contents)) {
            $playlist["tracks"] = parse_playlist_items($content_data->contents);

            $parse_func = fn ($content) => parse_playlist_items($content_data->contents);

            if (isset($content_data->continuations)) {
                if ($get_continuations) {
                    $playlist["tracks"] = array_merge(
                        $playlist["tracks"],
                        get_continuations(
                            $content_data,
                            "musicPlaylistShelfContinuation",
                            $limit,
                            $request_func,
                            $parse_func
                        )
                    );
                } else {
                    $playlist["continuation"] = nav($content_data, "continuations.0.nextContinuationData.continuation", true);
                }
            }
        }

        if ($playlistId === "LM") {
            $playlist["privacy"] = "PRIVATE";
        }

        $playlist = (object)$playlist;
        $playlist->duration_seconds = sum_total_duration($playlist);

        return $playlist;
    }

    /**
     * Returns the next set of tracks in a playlist.
     *
     * Known differences from Python version:
     *   - Function not available in Python version
     *
     * @param string $playlistId Playlist ID
     * @param string $token Continuation token
     * @return PlaylistContinuation
     */
    public function get_playlist_continuation($playlistId, $token)
    {
        $additional = "&ctoken={$token}&continuation={$token}&type=next";
        $results = $this->_send_request("browse", [], $additional);

        $continuation = nav($results, 'continuationContents.musicPlaylistShelfContinuation.continuations.0.nextContinuationData.continuation', true);
        $contents = nav($results, 'continuationContents.musicPlaylistShelfContinuation.contents', true);
        $tracks = parse_playlist_items($contents);

        return (object)[
            "id" => $playlistId,
            "tracks" => $tracks,
            "continuation" => $continuation,
        ];
    }

    /**
     * Gets playlist items for the 'Liked Songs' playlist
     *
     * @param int $limit How many items to return. Default: 100
     * @return Playlist List of playlistItem dictionaries. Same format as `get_playlist`
     */
    public function get_liked_songs($limit = 100)
    {
        return $this->get_playlist('LM', $limit);
    }

    /**
      * Gets playlist items of saved podcast episodes
      *
      * @param int $limit How many items to return. Default: 100
      * @return Playlist List of playlistItem dictionaries. Same format as `get_playlist`
      */
    public function get_saved_episodes($limit = 100)
    {
        return $this->get_playlist('SE', $limit);
    }

    /**
     * Creates a new empty playlist and returns its id.
     *
     * Known differences from Python version:
     *  - Throws exceptions with some common errors because there is no return response on error.
     *
     * @param string $title Playlist title
     * @param string $description Playlist description
     * @param string $privacy_status Playlists can be 'PUBLIC', 'PRIVATE', or 'UNLISTED'. Default: 'PRIVATE'
     * @param array $video_ids IDs of songs to create the playlist with
     * @param string $source_playlist Another playlist whose songs should be added to the new playlist
     * @return string|object ID of the YouTube playlist or full response if there was an error
     */
    public function create_playlist($title, $description, $privacy_status = "PRIVATE", $video_ids = null, $source_playlist = null)
    {
        $this->_check_auth();

        if ($video_ids && $source_playlist) {
            throw new \Exception("You can't specify both video_ids and source_playlist");
        }

        if (!in_array($privacy_status, ["PUBLIC", "PRIVATE", "UNLISTED"])) {
            throw new \Exception("Invalid privacy status, must be one of PUBLIC, PRIVATE, or UNLISTED");
        }

        $body = [
            "title" => $title,
            "description" => html_to_txt($description),
            "privacyStatus" => $privacy_status,
        ];

        if ($video_ids) {
            $body["videoIds"] = $video_ids;
        }

        if ($source_playlist) {
            $body["sourcePlaylistId"] = $source_playlist;
        }

        $response = $this->_send_request("playlist/create", $body);

        if (!empty($response->playlistId)) {
            return $response->playlistId;
        }

        if (!$response) {
            throw new \Exception("Failed to create playlist");
        }

        return $response;
    }

    /**
     * Edit title, description or privacyStatus of a playlist.
     * You may also move an item within a playlist or append another playlist to this playlist.
     *
     * Known differences from Python version:
     *  - Does a check for valid privacy status.
     *
     * @param string $playlistId Playlist id
     * @param string $title Optional. New title for the playlist
     * @param string $description Optional. New description for the playlist
     * @param string $privacyStatus Optional. New privacy status for the playlist
     * @param array $moveItem  Optional. Move one item before another. Items are specified by setVideoId, which is the
     *     unique id of this playlist item. See `get_playlist`
     * @param string $addPlaylistId Optional. Id of another playlist to add to this playlist
     * @param bool $addToTop Optional. Change the state of this playlist to add items to the top of the playlist (if true)
     *  or the bottom of the playlist (if false - this is also the default of a new playlist).
     * @return string Status String or full response
     */
    public function edit_playlist($playlistId, $title = null, $description = null, $privacyStatus = null, $moveItem = null, $addPlaylistId = null, $addToTop = null)
    {
        $this->_check_auth();
        $body = ['playlistId' => validate_playlist_id($playlistId)];
        $actions = [];

        if ($title) {
            $actions[] = ['action' => 'ACTION_SET_PLAYLIST_NAME', 'playlistName' => $title];
        }

        if ($description) {
            $actions[] = [
                'action' => 'ACTION_SET_PLAYLIST_DESCRIPTION',
                'playlistDescription' => $description
            ];
        }

        if ($privacyStatus) {
            if (!in_array($privacyStatus, ["PUBLIC", "PRIVATE", "UNLISTED"])) {
                throw new \Exception("Invalid privacy status, must be one of PUBLIC, PRIVATE, or UNLISTED");
            }

            $actions[] = [
                'action' => 'ACTION_SET_PLAYLIST_PRIVACY',
                'playlistPrivacy' => $privacyStatus
            ];
        }

        if ($moveItem) {
            $action = (object)[
                'action' => 'ACTION_MOVE_VIDEO_BEFORE',
                'setVideoId' => is_string($moveItem) ? $moveItem : $moveItem[0],
            ];

            if (is_array($moveItem) && count($moveItem) > 1) {
                $action->movedSetVideoIdSuccessor = $moveItem[1];
            }
            $actions[] = $action;
        }

        if ($addPlaylistId) {
            $actions[] = [
                'action' => 'ACTION_ADD_PLAYLIST',
                'addedFullListId' => $addPlaylistId
            ];
        }

        if ($addToTop === false) {
            $actions[] = ['action' => 'ACTION_SET_ADD_TO_TOP', 'addToTop' => 'false'];
        } elseif ($addToTop === true) {
            $actions[] = ['action' => 'ACTION_SET_ADD_TO_TOP', 'addToTop' => 'true'];
        }

        $body['actions'] = $actions;
        $endpoint = 'browse/edit_playlist';

        $response = $this->_send_request($endpoint, $body);
        return $response->status ?? $response;
    }

    /**
     * Delete a playlist.
     *
     * @param string $playlistId Playlist id
     * @return string|object Status String or full response
     */
    public function delete_playlist($playlistId)
    {
        $this->_check_auth();
        $body = ['playlistId' => validate_playlist_id($playlistId)];
        $endpoint = 'playlist/delete';
        $response = $this->_send_request($endpoint, $body);

        return empty($response->status) ? $response : $response->status;
    }

    /**
     * Add songs to an existing playlist.
     *
     * @param string $playlistId Playlist id
     * @param string|array $videoIds List of Video ids
     * @param string $source_playlist Playlist id of a playlist to add to the current playlist (no duplicate check)
     * @param bool $duplicates If true, duplicates will be added. If false, an error will be returned if there are duplicates (no items are added to the playlist)
     * @return string|object Status String and a dict containing the new setVideoId for each videoId or full response
     */
    public function add_playlist_items($playlistId, $videoIds = null, $source_playlist = null, $duplicates = false)
    {
        $this->_check_auth();

        $body = [
            'playlistId' => validate_playlist_id($playlistId),
            'actions' => [],
        ];

        if (!$videoIds && !$source_playlist) {
            throw new YTMusicUserError("You must provide either videoIds or a source_playlist to add to the playlist");
        }

        if ($videoIds) {
            foreach ($videoIds as $videoId) {
                $action = ['action' => 'ACTION_ADD_VIDEO', 'addedVideoId' => $videoId];
                if ($duplicates) {
                    $action['dedupeOption'] = 'DEDUPE_OPTION_SKIP';
                }
                $body['actions'][] = $action;
            }
        }

        if ($source_playlist) {
            $body['actions'][] = [
                'action' => 'ACTION_ADD_PLAYLIST',
                'addedFullListId' => $source_playlist
            ];

            // add an empty ACTION_ADD_VIDEO because otherwise
            // YTM doesn't return the object that maps videoIds to their new setVideoIds
            if (!$videoIds) {
                $body['actions'][] = ['action' => 'ACTION_ADD_VIDEO', 'addedVideoId' => null];
            }
        }

        $endpoint = 'browse/edit_playlist';
        $response = $this->_send_request($endpoint, $body);

        if (!empty($response->status) && $response->status === "STATUS_SUCCEEDED") {
            $result_dict = [];
            foreach ($response->playlistEditResults as $result_data) {
                $result_dict[] = $result_data->playlistEditVideoAddedResultData;
            }
            return (object)["status" => $response->status, "playlistEditResults" => $result_dict];
        }

        return $response;
    }

    /**
     * Remove songs from an existing playlist.
     *
     * @param string $playlistId Playlist id
     * @param Track[] $videos List of Tracks or Track like objects. Must contain videoId and setVideoId
     * @return string|object Status String or full response
     */
    public function remove_playlist_items($playlistId, $videos)
    {
        $this->_check_auth();

        $videos = array_filter($videos, function ($x) {
            return !empty($x->videoId) && !empty($x->setVideoId);
        });

        if (empty($videos)) {
            throw new YTMusicUserError("Cannot remove songs, because setVideoId is missing. Do you own this playlist?");
        }

        $body = [
            'playlistId' => validate_playlist_id($playlistId),
            'actions' => []
        ];

        foreach ($videos as $video) {
            $body['actions'][] = [
                'setVideoId' => is_array($video) ? $video['setVideoId'] : $video->setVideoId,
                'removedVideoId' => is_array($video) ? $video['videoId'] : $video->videoId,
                'action' => 'ACTION_REMOVE_VIDEO'
            ];
        }

        $endpoint = 'browse/edit_playlist';
        $response = $this->_send_request($endpoint, $body);

        if (!empty($response->status)) {
            return $response->status;
        }

        return $response;
    }
}
