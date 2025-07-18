<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Exception;
use JsonException;

class DependencyAnalyzer
{
    /**
     * @return string[]
     */
    public static function analyzeComposerDependencies(string $composerJsonPath = 'composer.json'): array
    {
        if (!file_exists($composerJsonPath)) {
            return [];
        }

        try {
            $composerContent = file_get_contents($composerJsonPath);
            if ($composerContent === false) {
                return [];
            }

            $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($composer)) {
                return [];
            }

            $warnings = [];
            $require = $composer['require'] ?? [];
            
            if (!is_array($require)) {
                return [];
            }

            // Check for AWS SDK
            if (isset($require['aws/aws-sdk-php'])) {
                $isOptimized = self::isAwsSdkOptimized($composer);
                if (!$isOptimized) {
                    $warnings[] = 'AWS SDK detected - optimize your deployment size: https://github.com/aws/aws-sdk-php/tree/master/src/Script/Composer';
                }
            }

            // Check for Google SDK
            if (isset($require['google/apiclient']) || isset($require['google/cloud'])) {
                $isOptimized = self::isGoogleSdkOptimized($composer);
                if (!$isOptimized) {
                    $warnings[] = 'Google SDK detected - optimize your deployment size: https://github.com/googleapis/google-api-php-client#cleaning-up-unused-services';
                }
            }

            return $warnings;

        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Check if AWS SDK is optimized by looking for custom scripts or exclusions
     * @param array<string, mixed> $composer
     */
    private static function isAwsSdkOptimized(array $composer): bool
    {
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
        $extra = $composer['extra'] ?? [];
        if (is_array($extra) && isset($extra['aws']) && is_array($extra['aws']) && isset($extra['aws']['includes'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if Google SDK is optimized by looking for custom exclusions
     * @param array<string, mixed> $composer
     */
    private static function isGoogleSdkOptimized(array $composer): bool
    {
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
        $extra = $composer['extra'] ?? [];
        if (is_array($extra) && isset($extra['google']) && is_array($extra['google']) && isset($extra['google']['exclude_files'])) {
            return true;
        }

        return false;
    }
}