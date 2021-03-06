<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Dialog class provides helpers to interact with the user.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DialogHelper extends Helper
{
    private $inputStream;
    private static $shell;
    private static $stty;

    /**
     * Asks a question to the user.
     *
     * @param OutputInterface $output   An Output instance
     * @param string|array    $question The question to ask
     * @param string          $default  The default answer if none is given by the user
     *
     * @return string The user answer
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     */
    public function ask(OutputInterface $output, $question, $default = null)
    {
        $output->write($question);

        $ret = fgets($this->inputStream ?: STDIN, 4096);
        if (false === $ret) {
            throw new \RuntimeException('Aborted');
        }
        $ret = trim($ret);

        return strlen($ret) > 0 ? $ret : $default;
    }

    /**
     * Asks a confirmation to the user.
     *
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param OutputInterface $output   An Output instance
     * @param string|array    $question The question to ask
     * @param Boolean         $default  The default answer if the user enters nothing
     *
     * @return Boolean true if the user has confirmed, false otherwise
     */
    public function askConfirmation(OutputInterface $output, $question, $default = true)
    {
        $answer = 'z';
        while ($answer && !in_array(strtolower($answer[0]), array('y', 'n'))) {
            $answer = $this->ask($output, $question);
        }

        if (false === $default) {
            return $answer && 'y' == strtolower($answer[0]);
        }

        return !$answer || 'y' == strtolower($answer[0]);
    }

    /**
     * Asks a question to the user, the response is hidden
     *
     * @param OutputInterface $output   An Output instance
     * @param string|array    $question The question
     * @param Boolean         $fallback In case the response can not be hidden, whether to fallback on non-hidden question or not
     *
     * @return string         The answer
     *
     * @throws \RuntimeException In case the fallback is deactivated and the response can not be hidden
     */
    public function askHiddenResponse(OutputInterface $output, $question, $fallback = true)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $exe = __DIR__ . '/../../Resources/bin/hiddeninput.exe';

            // handle code running from a phar
            if ('phar:' === substr(__FILE__, 0, 5)) {
                $tmpExe = sys_get_temp_dir() . '/../../Resources/bin/hiddeninput.exe';
                copy($exe, $tmpExe);
                $exe = $tmpExe;
            }

            $output->write($question);
            $value = rtrim(shell_exec($exe));
            $output->writeln('');

            if (isset($tmpExe)) {
                unlink($tmpExe);
            }

            return $value;
        }

        if ($this->hasSttyAvailable()) {
            $output->write($question);

            $sttyMode = shell_exec('/usr/bin/env stty -g');

            shell_exec('/usr/bin/env stty -echo');
            $value = fgets($this->inputStream ?: STDIN, 4096);
            shell_exec(sprintf('/usr/bin/env stty %s', $sttyMode));

            if (false === $value) {
                throw new \RuntimeException('Aborted');
            }

            $value = trim($value);
            $output->writeln('');

            return $value;
        }

        if (false !== $shell = $this->getShell()) {
            $output->write($question);
            $readCmd = $shell === 'csh' ? 'set mypassword = $<' : 'read -r mypassword';
            $command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
            $value = rtrim(shell_exec($command));
            $output->writeln('');

            return $value;
        }

        if ($fallback) {
            return $this->ask($output, $question);
        }

        throw new \RuntimeException('Unable to hide the response');
    }

    /**
     * Asks for a value and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param OutputInterface $output    An Output instance
     * @param string|array    $question  The question to ask
     * @param callable        $validator A PHP callback
     * @param integer         $attempts  Max number of times to ask before giving up (false by default, which means infinite)
     * @param string          $default   The default answer if none is given by the user
     *
     * @return mixed
     *
     * @throws \Exception When any of the validators return an error
     */
    public function askAndValidate(OutputInterface $output, $question, $validator, $attempts = false, $default = null)
    {
        $that = $this;

        $interviewer = function() use ($output, $question, $default, $that) {
            return $that->ask($output, $question, $default);
        };

        return $this->validateAttempts($interviewer, $output, $validator, $attempts);
    }

    /**
     * Asks for a value, hide and validates the response.
     *
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param OutputInterface $output    An Output instance
     * @param string|array    $question  The question to ask
     * @param callable        $validator A PHP callback
     * @param integer         $attempts  Max number of times to ask before giving up (false by default, which means infinite)
     * @param Boolean         $fallback  In case the response can not be hidden, whether to fallback on non-hidden question or not
     *
     * @return string         The response
     *
     * @throws \Exception        When any of the validators return an error
     * @throws \RuntimeException In case the fallback is deactivated and the response can not be hidden
     *
     */
    public function askHiddenResponseAndValidate(OutputInterface $output, $question, $validator, $attempts = false, $fallback = true)
    {
        $that = $this;

        $interviewer = function() use ($output, $question, $fallback, $that) {
            return $that->askHiddenResponse($output, $question, $fallback);
        };

        return $this->validateAttempts($interviewer, $output, $validator, $attempts);
    }

    /**
     * Sets the input stream to read from when interacting with the user.
     *
     * This is mainly useful for testing purpose.
     *
     * @param resource $stream The input stream
     */
    public function setInputStream($stream)
    {
        $this->inputStream = $stream;
    }

    /**
     * Returns the helper's input stream
     *
     * @return string
     */
    public function getInputStream()
    {
        return $this->inputStream;
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'dialog';
    }

    /**
     * Return a valid unix shell
     *
     * @return string|false  The valid shell name, false in case no valid shell is found
     */
    private function getShell()
    {
        if (null !== self::$shell) {
            return self::$shell;
        }

        self::$shell = false;

        if (file_exists('/usr/bin/env')) {
            // handle other OSs with bash/zsh/ksh/csh if available to hide the answer
            $test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
            foreach (array('bash', 'zsh', 'ksh', 'csh') as $sh) {
                if ('OK' === rtrim(shell_exec(sprintf($test, $sh)))) {
                    self::$shell = $sh;
                    break;
                }
            }
        }

        return self::$shell;
    }

    private function hasSttyAvailable()
    {
        if (null !== self::$stty) {
            return self::$stty;
        }

        exec('/usr/bin/env stty', $output, $exitcode);

        return self::$stty = $exitcode === 0;
    }

    /**
     * Validate an attempt
     *
     * @param callable         $interviewer  A callable that will ask for a question and return the result
     * @param OutputInterface  $output       An Output instance
     * @param callable         $validator    A PHP callback
     * @param integer          $attempts     Max number of times to ask before giving up ; false will ask infinitely
     *
     * @return string   The validated response
     *
     * @throws \Exception In case the max number of attempts has been reached and no valid response has been given
     */
    private function validateAttempts($interviewer, OutputInterface $output, $validator, $attempts)
    {
        $error = null;
        while (false === $attempts || $attempts--) {
            if (null !== $error) {
                $output->writeln($this->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
            }

            try {
                return call_user_func($validator, $interviewer());
            } catch (\Exception $error) {
            }
        }

        throw $error;
    }
}
