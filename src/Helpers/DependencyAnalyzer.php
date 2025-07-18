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

            // Check for AWS SDK in composer.json or vendor directory
            $hasAwsSdk = isset($require['aws/aws-sdk-php']) || 
                         (is_dir('vendor/aws/aws-sdk-php') && file_exists('vendor/aws/aws-sdk-php/composer.json'));
            
            if ($hasAwsSdk) {
                $isOptimized = self::isAwsSdkOptimized($composer);
                if (!$isOptimized) {
                    $warnings[] = 'AWS SDK detected - optimize your deployment size: https://github.com/aws/aws-sdk-php/tree/master/src/Script/Composer';
                }
            }

            // Check for Google SDK in composer.json or vendor directory
            $hasGoogleSdk = isset($require['google/apiclient']) || 
                           (is_dir('vendor/google/apiclient') && file_exists('vendor/google/apiclient/composer.json'));
            
            if ($hasGoogleSdk) {
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
                if (is_string($script) && str_contains($script, 'Aws\\Script\\Composer\\Composer::removeUnusedServices')) {
                    return true;
                }
            }
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
                if (is_string($script) && str_contains($script, 'Google\\Task\\Composer::cleanup')) {
                    return true;
                }
            }
        }

        return false;
    }
}