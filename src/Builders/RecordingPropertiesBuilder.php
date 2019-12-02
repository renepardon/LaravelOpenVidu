<?php

namespace SquareetLabs\LaravelOpenVidu\Builders;

use SquareetLabs\LaravelOpenVidu\Enums\MediaMode;
use SquareetLabs\LaravelOpenVidu\Enums\OutputMode;
use SquareetLabs\LaravelOpenVidu\Enums\RecordingLayout;
use SquareetLabs\LaravelOpenVidu\RecordingProperties;

/**
 * Class RecordingPropertiesFactory
 * @package SquareetLabs\LaravelOpenVidu\Builders
 */
class RecordingPropertiesBuilder
{
    /**
     * @param $properties
     * @return RecordingProperties|null
     */
    public static function build($properties)
    {
        if (is_array($properties)) {
            return new RecordingProperties(
                array_key_exists('hasAudio', $properties) ? $properties['hasAudio'] : true,
                array_key_exists('hasVideo', $properties) ? $properties['hasVideo'] : true,
                array_key_exists('name', $properties) ? $properties['name'] : RecordingLayout::BEST_FIT,
                array_key_exists('outputMode', $properties) ? $properties['outputMode'] : OutputMode::COMPOSED,
                array_key_exists('recordingLayout', $properties) ? $properties['recordingLayout'] : RecordingLayout::BEST_FIT,
                array_key_exists('customLayout', $properties) ? $properties['customLayout'] : MediaMode::ROUTED,
                array_key_exists('resolution', $properties) ? $properties['resolution'] : null
            );
        }
        return null;

    }
}