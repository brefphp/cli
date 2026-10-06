<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Cli\IO;
use Bref\Cli\Commands\Deploy;
use Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployTest extends CommandTestCase
{
    public function test_params_are_only_supported_for_serverless_yml_applications(): void
    {
        $configFile = sys_get_temp_dir() . '/bref-cli-test-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($configFile, '<?php echo json_encode(["name" => "shop", "team" => "acme", "type" => "laravel"]);');
        $input = new ArrayInput(['--config' => $configFile, '--param' => ['domain=example.com']]);
        $input->setInteractive(false);
        $output = new BufferedOutput;
        IO::init($input, $output);

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('The --param option is only supported for serverless.yml applications');
            (new Deploy)->run($input, $output);
        } finally {
            unlink($configFile);
        }
    }
}
