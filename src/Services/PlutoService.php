<?php

namespace ChannelsBuddy\Pluto\Services;

use ChannelsBuddy\SourceProvider\Models\Airing;
use ChannelsBuddy\SourceProvider\Models\Channel;
use ChannelsBuddy\SourceProvider\Models\Channels;
use ChannelsBuddy\SourceProvider\Models\Guide;
use ChannelsBuddy\SourceProvider\Models\GuideEntry;
use ChannelsBuddy\SourceProvider\Models\Rating;
use ChannelsBuddy\SourceProvider\Contracts\ChannelSource;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\LazyCollection;
use JsonMachine\JsonMachine;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Ramsey\Uuid\Uuid;
use stdClass;

class PlutoService implements ChannelSource
{
    protected $baseUrl;
    protected $httpClient;

    protected $genres = [
        "Children" => [
            "Kids",
            "Children & Family",
            "Kids' TV",
            "Cartoons",
            "Animals",
            "Family Animation",
            "Ages 2-4",
            "Ages 11-12"
        ],
        "News" => [
            "News + Opinion",
            "General News"
        ],
        "Sports" => [
            "Sports",
            "Sports & Sports Highlights",
            "Sports Documentaries"
        ],
        "Drama" => [
            "Crime",
            "Action & Adventure",
            "Thrillers",
            "Romance",
            "Sci-Fi & Fantasy",
            "Teen Dramas",
            "Film Noir",
            "Romantic Comedies",
            "Indie Dramas",
            "Romance Classics",
            "Crime Action",
            "Action Sci-Fi & Fantasy",
            "Action Thrillers",
            "Crime Thrillers",
            "Political Thrillers",
            "Classic Thrillers",
            "Classic Dramas",
            "Sci-Fi Adventure",
            "Romantic Dramas",
            "Mystery",
            "Psychological Thrillers",
            "Foreign Classic Dramas",
            "Classic Westerns",
            "Westerns",
            "Sci-Fi Dramas",
            "Supernatural Thrillers",
            "Mobster",
            "Action Classics",
            "African-American Action",
            "Suspense",
            "Family Dramas",
            "Alien Sci-Fi",
            "Sci-Fi Cult Classics"
        ]
    ];

    public function __construct()
    {
        $this->baseUrl = 'http://api.pluto.tv';

        $this->httpClient = new Client(['base_uri' => $this->baseUrl]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getChannels(?string $device = null): Channels
    {
        $stream = $this->httpClient->get('/v2/channels');
        $json = \GuzzleHttp\Psr7\StreamWrapper::getResource(
            $stream->getBody()
        );

        $channels = LazyCollection::make(JsonMachine::fromStream(
            $json, '', new ExtJsonDecoder
        ))->filter(function($channel){
            return $channel->isStitched && !preg_match(
                '/^announcement|^privacy-policy/',
                $channel->slug
            );
        })->map(function($channel) {
            return $this->generateChannel($channel);
        })->keyBy('id');

        return new Channels($channels);
    }
 
    public function getGuideData(?int $startTimestamp, ?int $duration, ?string $device = null): Guide
    {
        if (is_null($startTimestamp)) {
            $startTimestamp = Carbon::now()->timestamp;
        }

        if (is_null($duration)) {
            $duration = config("channels.channelSources.pluto.guideChunkSize");
        }
        
        $guideEntries = LazyCollection::make(function() use ($startTimestamp, $duration) {
            $startTimestamp = Carbon::createFromTimestamp($startTimestamp);
            $startTime = urlencode(
                $startTimestamp->format('Y-m-d H:i:s.vO')
            );
            $stopTime = urlencode(
                $startTimestamp
                    ->copy()
                    ->addSeconds($duration)
                    ->format('Y-m-d H:i:s.vO')
            );

            $stream = $this->httpClient->get(
                sprintf('/v2/channels?start=%s&stop=%s', $startTime, $stopTime));
            $json = \GuzzleHttp\Psr7\StreamWrapper::getResource(
                $stream->getBody()
            );

            $guideData = LazyCollection::make(JsonMachine::fromStream(
                $json, '', new ExtJsonDecoder
            ))->filter(function($channel){
                return $channel->isStitched && !preg_match(
                    '/^announcement|^privacy-policy/',
                    $channel->slug
                );
            })->keyBy('slug');

            foreach ($guideData as $channel) {
                $guideEntry = new GuideEntry(
                    $this->generateChannel($channel)
                );

                $airings = LazyCollection::make(function() use ($channel) {
                    foreach ($channel->timelines as $channelAiring) {
                        $startTime = Carbon::parse($channelAiring->start);
                        $stopTime = Carbon::parse($channelAiring->stop);
                        $length = $startTime->copy()->diffInSeconds($stopTime);
                        $airingId = md5(
                            $channel->slug.$startTime->copy()->timestamp
                        );
                        $isMovie = $channelAiring->episode->series->type == "film";

                        $airing = new Airing([
                            'id'                    => $airingId,
                            'channelId'             => $channel->slug,
                            'source'                => "pluto",
                            'title'                 => $channelAiring->title,
                            'description'           => $channelAiring->episode->description,
                            'startTime'             => $startTime,
                            'stopTime'              => $stopTime,
                            'length'                => $length,
                            'programId'             => $channelAiring->episode->_id,
                            'isMovie'               => $isMovie
                        ]);

                        if (!$isMovie && $channelAiring->title !=
                            $channelAiring->episode->name) {
                            $airing->setSubTitle($channelAiring->episode->name);
                        }

                        if ($isMovie && isset($channelAiring->episode->poster)) {
                            $airingArt = $channelAiring->episode->poster->path;
                        } else {
                            $airingArt = str_replace("h=660", "h=900",
                                str_replace("w=660", "w=900",
                                    $channelAiring->episode->series->tile->path
                                    ?? null
                                )
                            );
                        }
                        $airing->setImage($airingArt);

                        if (isset($channelAiring->episode->series->_id)) {
                            $airing->setSeriesId($channelAiring->episode->series->_id);
                        }

                        if (!$isMovie) {
                            $airing->setEpisodeNumber($channelAiring->episode->number);
                        }

                        $originalReleaseDate = Carbon::parse(
                            $channelAiring->episode->clip->originalReleaseDate ?? null
                        );

                        $firstAiredDate = Carbon::parse(
                            $channelAiring->episode->firstAired  ?? null
                        );

                        $airing->setOriginalReleaseDate($originalReleaseDate);
                        $airing->setFirstAiredDate($firstAiredDate);

                        if (!$isMovie && $firstAiredDate->isPast()) {
                            $airing->setIsPreviouslyShown(true);
                        } elseif (!$isMovie) {
                            $airing->setIsNew(true);
                        }

                        $airing->addCategory($isMovie ? "Movie" : "Series");
                        $airing->addCategory($channelAiring->episode->genre);
                        $airing->addCategory($channelAiring->episode->subGenre);
                        foreach($this->genres as $genreName => $genres) {
                            if (
                                in_array($channelAiring->episode->genre, $genres) ||
                                in_array($channelAiring->episode->subGenre, $genres) ||
                                in_array($channel->category, $genres)
                            ) {
                                $airing->addCategory($genreName);
                            }
                        }

                        $airing->addRating(new Rating(
                            $channelAiring->episode->rating));

                        yield $airing;
                    }
                });
                $guideEntry->airings = $airings;
                yield $guideEntry;
            }
        });
        
        return new Guide($guideEntries);
    }

    private function generateChannel(stdClass $channel): Channel
    {
        $description = $this->getCleanDescription($channel->summary);
        $channelArt = $this->getChannelArt($channel->featuredImage->path);
        $streamUrl = $this->getStreamUrl($channel->stitched->urls[0]->url);
        
        return new Channel([
            "id"            => $channel->slug,
            "name"          => $channel->name,
            "number"        => $channel->number,
            "title"         => $channel->name,
            "callSign"      => $channel->hash,
            "description"   => $description,
            "logo"          => $channel->colorLogoPNG->path ?? null,
            "channelArt"    => $channelArt,
            "category"      => $channel->category,
            "streamUrl"     => $streamUrl
        ]);
    }

    private function getCleanDescription(string $description): string
    {
        return preg_replace('/("|“|”)/m', '',
            preg_replace('/(\r\n|\n|\r)/m', ' ', $description)
        );
    }

    private function getChannelArt(string $image): string
    {
        return str_replace("h=900", "h=562",
                str_replace("w=1600", "w=1000", $image)
            );
    }

    private function getStreamUrl(string $url): string
    {
        $params = http_build_query([
            'advertisingId'         => '',
            'appName'               => 'web',
            'appVersion'            => 'unknown',
            'appStoreUrl'           => '',
            'architecture'          => '',
            'buildVersion'          => '',
            'clientTime'            => '0',
            'deviceDNT'             => '0',
            'deviceId'              => Uuid::uuid1()->toString(),
            'deviceMake'            => 'Chrome',
            'deviceModel'           => 'web',
            'deviceType'            => 'web',
            'deviceVersion'         => 'unknown',
            'includeExtendedEvents' => 'false',
            'sid'                   => Uuid::uuid4()->toString(),
            'userId'                => '',
            'serverSideAds'         => 'true'
        ]);

        return strtok($url, "?") . "?" . $params;
    }
}