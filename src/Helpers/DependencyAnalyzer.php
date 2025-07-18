<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Exception;
use JsonException;

class DependencyAnalyzer
{
    /**
     * @return array{warnings: string[], suggestions: string[]}
     */
    public static function analyzeComposerDependencies(string $composerJsonPath = 'composer.json'): array
    {
        if (!file_exists($composerJsonPath)) {
            return ['warnings' => [], 'suggestions' => []];
        }

        try {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent === false) {
                return ['warnings' => [], 'suggestions' => []];
            }

            $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($composer)) {
                return ['warnings' => [], 'suggestions' => []];
            }

            $warnings = [];
            $suggestions = [];

            // Check for AWS SDK
            $require = $composer['require'] ?? [];
            if (is_array($require) && isset($require['aws/aws-sdk-php'])) {
                $isOptimized = self::isAwsSdkOptimized($composerJsonPath);
                if (!$isOptimized) {
                    $warnings[] = 'AWS SDK detected - this can significantly increase deployment size';
                    $suggestions[] = 'Consider optimizing AWS SDK by including only required services: https://github.com/aws/aws-sdk-php/tree/master/src/Script/Composer';
                }
            }

            // Check for Google SDK
            if (is_array($require) && (isset($require['google/apiclient']) || isset($require['google/cloud']))) {
                $isOptimized = self::isGoogleSdkOptimized($composerJsonPath);
                if (!$isOptimized) {
                    $warnings[] = 'Google SDK detected - this can significantly increase deployment size';
                    $suggestions[] = 'Consider optimizing Google SDK by removing unused services: https://github.com/googleapis/google-api-php-client#cleaning-up-unused-services';
                }
            }

            return ['warnings' => $warnings, 'suggestions' => $suggestions];

        } catch (JsonException) {
            return ['warnings' => [], 'suggestions' => []];
        }
    }

    /**
     * Check if AWS SDK is optimized by looking for custom scripts or exclusions
     */
    private static function isAwsSdkOptimized(string $composerJsonPath): bool
    {
        try {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent === false) {
                return false;
            }

            $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($composer)) {
                return false;
            }

            // Check for AWS SDK optimization script
            $scripts = $composer['scripts'] ?? [];
            if (is_array($scripts) && isset($scripts['pre-autoload-dump'])) {
                $preAutoloadDump = (array) $scripts['pre-autoload-dump'];
                foreach ($preAutoloadDump as $script) {
                    if (is_string($script) && str_contains($script, 'aws-sdk-php') && str_contains($script, 'remove-unused-services')) {
                        return true;
                    }
                }
            }

            // Check for custom AWS SDK optimizations in extra section
            if (isset($composer['extra']['aws']['includes'])) {
                return true;
            }

            return false;

        } catch (Exception) {
            return false;
        }
    }

    /**
     * Check if Google SDK is optimized by looking for custom exclusions
     */
    private static function isGoogleSdkOptimized(string $composerJsonPath): bool
    {
        try {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent === false) {
                return false;
            }

            $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($composer)) {
                return false;
            }

            // Check for Google SDK optimization script
            $scripts = $composer['scripts'] ?? [];
            if (is_array($scripts) && isset($scripts['pre-autoload-dump'])) {
                $preAutoloadDump = (array) $scripts['pre-autoload-dump'];
                foreach ($preAutoloadDump as $script) {
                    if (is_string($script) && str_contains($script, 'google') && str_contains($script, 'remove-unused-services')) {
                        return true;
                    }
                }
            }

            // Check for custom Google SDK optimizations in extra section
            if (isset($composer['extra']['google']['exclude_files'])) {
                return true;
            }

            return false;

        } catch (Exception) {
            return false;
        }
    }
}