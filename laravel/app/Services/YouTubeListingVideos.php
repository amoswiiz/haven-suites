<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class YouTubeListingVideos
{
    /**
     * @param  array<int, array{url?: ?string, title?: ?string}>  $input
     * @return list<array{video_id: string, title: ?string}>
     */
    public function normalize(array $input): array
    {
        if (count($input) > 2) {
            throw ValidationException::withMessages(['youtube_videos' => 'Add at most two YouTube videos.']);
        }
        $videos = [];
        $seen = [];
        foreach ($input as $index => $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['url', 'title']) !== []) {
                throw ValidationException::withMessages(['youtube_videos.'.$index => 'Use a YouTube URL and an optional video title.']);
            }
            $url = $item['url'] ?? '';
            $title = $item['title'] ?? '';
            if (! is_string($url) || ! is_string($title)) {
                throw ValidationException::withMessages(['youtube_videos.'.$index => 'Use a YouTube URL and an optional video title.']);
            }
            $url = trim($url);
            $title = trim($title);
            if (! $this->validTitle($title)) {
                throw ValidationException::withMessages(['youtube_videos.'.$index.'.title' => 'Use a plain text video title of at most 120 characters.']);
            }
            if ($url === '' && $title === '') {
                continue;
            }
            $id = $this->videoId($url);
            if ($id === null) {
                throw ValidationException::withMessages(['youtube_videos.'.$index.'.url' => 'Enter an HTTPS YouTube video link. Playlist links and embed HTML are not supported.']);
            }
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['youtube_videos.'.$index.'.url' => 'This video is already included. Choose a different video or leave this field empty.']);
            }
            $seen[$id] = true;
            $videos[] = ['video_id' => $id, 'title' => $title !== '' ? $title : null];
        }

        return $videos;
    }

    /** @return list<array{video_id: string, title: string, watch_url: string, embed_url: string, thumbnail_url: string}> */
    public function project(mixed $stored): array
    {
        return array_map(static fn (array $video): array => [
            'video_id' => $video['video_id'], 'title' => $video['title'] ?? 'Apartment video tour',
            'watch_url' => 'https://www.youtube.com/watch?v='.$video['video_id'],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$video['video_id'].'?rel=0',
            'thumbnail_url' => 'https://i.ytimg.com/vi/'.$video['video_id'].'/hqdefault.jpg',
        ], $this->stored($stored));
    }

    /** @return list<array{url: string, title: string}> */
    public function forForm(mixed $stored): array
    {
        return array_map(static fn (array $video): array => [
            'url' => 'https://www.youtube.com/watch?v='.$video['video_id'], 'title' => $video['title'] ?? '',
        ], $this->stored($stored));
    }

    private function videoId(string $url): ?string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            if (preg_match('#^/([a-zA-Z0-9_-]{11})/?$#D', $path, $matches)) {
                $id = $matches[1];
            }
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:embed|shorts|live)/([a-zA-Z0-9_-]{11})/?$#D', $path, $matches)) {
                $id = $matches[1];
            } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) && in_array($path, ['/watch', '/watch/'], true)) {
                foreach (explode('&', $parts['query'] ?? '') as $parameter) {
                    [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
                    if (urldecode($key) === 'v') {
                        if ($id !== null) {
                            return null;
                        }
                        $id = urldecode($value);
                    }
                }
            }
        }

        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id) ? $id : null;
    }

    private function validTitle(string $title): bool
    {
        return mb_check_encoding($title, 'UTF-8') && mb_strlen($title) <= 120 && ! preg_match('/[<>\x00-\x1f\x7f]/u', $title);
    }

    /** @return list<array{video_id: string, title: ?string}> */
    private function stored(mixed $stored): array
    {
        if (is_string($stored)) {
            try {
                $stored = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (! is_array($stored) || ! array_is_list($stored) || count($stored) > 2) {
            return [];
        }
        $videos = [];
        $seen = [];
        foreach ($stored as $video) {
            if (! is_array($video) || array_diff(array_keys($video), ['video_id', 'title']) !== []
                || ! is_string($video['video_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{11}$/D', $video['video_id'])
                || (($video['title'] ?? null) !== null && ! is_string($video['title']))
                || ! $this->validTitle($video['title'] ?? '') || isset($seen[$video['video_id']])) {
                return [];
            }
            $seen[$video['video_id']] = true;
            $videos[] = ['video_id' => $video['video_id'], 'title' => trim($video['title'] ?? '') !== '' ? trim($video['title']) : null];
        }

        return $videos;
    }
}
