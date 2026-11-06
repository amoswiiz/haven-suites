<?php

namespace Tests\Unit;

use App\Services\YouTubeListingVideos;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubeListingVideosTest extends TestCase
{
    #[DataProvider('supportedUrls')]
    public function test_supported_youtube_urls_are_normalized_to_canonical_metadata(string $url): void
    {
        $service = new YouTubeListingVideos;
        $this->assertSame([['video_id' => 'abCDef_1234', 'title' => 'Apartment tour']], $service->normalize([['url' => $url, 'title' => ' Apartment tour ']]));
        $projected = $service->project(json_encode($service->normalize([['url' => $url]]), JSON_THROW_ON_ERROR));
        $this->assertSame('abCDef_1234', $projected[0]['video_id']);
        $this->assertSame('https://www.youtube.com/watch?v=abCDef_1234', $projected[0]['watch_url']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/abCDef_1234?rel=0', $projected[0]['embed_url']);
        $this->assertSame('https://i.ytimg.com/vi/abCDef_1234/hqdefault.jpg', $projected[0]['thumbnail_url']);
        $this->assertSame('Apartment video tour', $projected[0]['title']);
        $this->assertSame([['url' => 'https://www.youtube.com/watch?v=abCDef_1234', 'title' => '']], $service->forForm($service->normalize([['url' => $url]])));
    }

    public static function supportedUrls(): array
    {
        return [
            ['https://www.youtube.com/watch?v=abCDef_1234'],
            ['https://youtube.com/watch?v=abCDef_1234&feature=shared&list=ignored'],
            ['https://m.youtube.com/watch?v=abCDef_1234'],
            ['https://youtu.be/abCDef_1234?si=tracking-removed&t=10'],
            ['https://www.youtube.com/embed/abCDef_1234?autoplay=1'],
            ['https://www.youtube.com/shorts/abCDef_1234'],
            ['https://youtube.com/live/abCDef_1234/?feature=shared'],
            ['https://www.youtube-nocookie.com/embed/abCDef_1234'],
            ['https://www.youtube.com:443/watch?v=abCDef_1234'],
            [' HTTPS://WWW.YOUTUBE.COM/watch?v=abCDef_1234 '],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_or_non_video_urls_are_rejected(string $url): void
    {
        $this->expectException(ValidationException::class);
        (new YouTubeListingVideos)->normalize([['url' => $url]]);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://www.youtube.com/watch?v=abCDef_1234'],
            ['//www.youtube.com/watch?v=abCDef_1234'],
            ['javascript:alert(1)'],
            ['<iframe src="https://www.youtube.com/embed/abCDef_1234"></iframe>'],
            ['https://www.youtube.com.evil.example/watch?v=abCDef_1234'],
            ['https://evil.example/youtube.com/watch?v=abCDef_1234'],
            ['https://www.youtube.com@evil.example/watch?v=abCDef_1234'],
            ['https://attacker@www.youtube.com/watch?v=abCDef_1234'],
            ['https://www.youtube.com:8443/watch?v=abCDef_1234'],
            ['https://youtu.be/abCDef_1234/extra'],
            ['https://www.youtube.com/playlist?list=abCDef_1234'],
            ['https://www.youtube.com/watch?list=abCDef_1234'],
            ['https://www.youtube.com/watch?v[]=abCDef_1234'],
            ['https://www.youtube.com/watch?v=abCDef_1234&v=Z9-xY8wV7u6'],
            ['https://youtu.be/abCDef_123'],
            ['https://youtu.be/abCDef_12345'],
            ['https://youtu.be/abCDef!1234'],
            ['https://www.youtube.com/embed/abCDef_1234"onload="alert(1)'],
            ['https://www.youtube.com\\evil.example/watch?v=abCDef_1234'],
            ["https://www.youtube.com/watch?v=abCDef_1234\nHeader:secret"],
            ['https://www.youtube.com/watch?v='.str_repeat('x', 2100)],
        ];
    }

    public function test_empty_slots_are_ignored_but_a_title_without_a_video_is_rejected(): void
    {
        $service = new YouTubeListingVideos;
        $this->assertSame([], $service->normalize([['url' => '', 'title' => ''], ['url' => null, 'title' => null]]));
        try {
            $service->normalize([['url' => '', 'title' => 'Tour']]);
            $this->fail('A title must have a video link.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('youtube_videos.0.url', $exception->errors());
        }
    }

    public function test_duplicate_videos_and_more_than_two_videos_are_rejected(): void
    {
        $service = new YouTubeListingVideos;
        foreach ([
            [['url' => 'https://youtu.be/abCDef_1234'], ['url' => 'https://www.youtube.com/watch?v=abCDef_1234']],
            [['url' => ''], ['url' => ''], ['url' => '']],
        ] as $input) {
            try {
                $service->normalize($input);
                $this->fail('Duplicate or excessive video slots must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_html_and_long_titles_are_rejected(): void
    {
        foreach (['<script>alert(1)</script>', str_repeat('x', 121), "Tour\nTitle"] as $title) {
            try {
                (new YouTubeListingVideos)->normalize([['url' => 'https://youtu.be/abCDef_1234', 'title' => $title]]);
                $this->fail('Video titles must contain short plain text.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('youtube_videos.0.title', $exception->errors());
            }
        }
    }

    #[DataProvider('invalidStoredValues')]
    public function test_invalid_legacy_metadata_fails_closed_without_throwing(mixed $stored): void
    {
        $service = new YouTubeListingVideos;
        $this->assertSame([], $service->project($stored));
        $this->assertSame([], $service->forForm($stored));
    }

    public static function invalidStoredValues(): array
    {
        return [
            [null], ['{broken'], ['null'], ['42'], ['{"video_id":"abCDef_1234"}'],
            [[['video_id' => 'bad-id', 'title' => null]]],
            [[['video_id' => 'abCDef_1234', 'title' => '<img src=x onerror=alert(1)>']]],
            [[['video_id' => 'abCDef_1234', 'title' => ['invalid']]]],
            [[['video_id' => 'abCDef_1234', 'embed_url' => 'https://evil.example/embed']]],
            [[['video_id' => 'abCDef_1234'], ['video_id' => 'abCDef_1234']]],
            [[['video_id' => 'abCDef_1234'], ['video_id' => 'Z9-xY8wV7u6'], ['video_id' => 'X1y2Z3a4B5c']]],
        ];
    }
}
