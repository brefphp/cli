<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Cli\IO;
use Bref\Cli\Commands\Remove;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RemoveTest extends CommandTestCase
{
    public function test_shows_the_message_from_bref_cloud(): void
    {
        [$status, $display] = $this->remove(['message' => 'Secrets of this environment are kept in SSM Parameter Store under /shop/prod/.']);

        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString('Secrets of this environment are kept in SSM Parameter Store under /shop/prod/.', $display);
    }

    public function test_bref_cloud_may_return_no_message(): void
    {
        [$status, $display] = $this->remove([]);

        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString('removed', $display);
    }

    /**
     * @param array<string, mixed> $deletion What Bref Cloud returns when asked to remove the environment
     * @return array{int, string} The exit code and the output
     */
    private function remove(array $deletion): array
    {
        $command = new Remove($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12' => fn(string $method) => $method === 'DELETE'
                ? $this->json($deletion, 202)
                : $this->json(['message' => 'Not found'], 404),
        ]));
        $configFile = sys_get_temp_dir() . '/bref-cli-test-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($configFile, "service: shop\nbref:\n    team: acme\n");
        $input = new ArrayInput(['--env' => 'prod', '--config' => $configFile]);
        $input->setInteractive(false);
        $output = new BufferedOutput;
        IO::init($input, $output);

        $status = $command->run($input, $output);
        IO::stop();
        unlink($configFile);

        return [$status, $output->fetch()];
    }
}
