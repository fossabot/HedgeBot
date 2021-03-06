<?php

namespace HedgeBot\Core\Console\Twitch;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use HedgeBot\Core\Console\StorageAwareCommand;
use HedgeBot\Core\Service\Twitch\AuthManager;

/**
 * Class RevokeAccessTokenCommand
 * @package HedgeBot\Core\Console\Twitch
 */
class RevokeAccessTokenCommand extends StorageAwareCommand
{
    /**
     *
     */
    public function configure()
    {
        $this->setName('twitch:revoke-access-token')
            ->setDescription('Revokes an auth access token from a channel.')
            ->addArgument('channel', InputArgument::REQUIRED, 'The channel from which the token will be revoked.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getConfigStorage();
        $channel = $input->getArgument('channel');

        $twitchAuth = new AuthManager($config->get('twitch.auth.clientId'), $this->getDataStorage());
        $twitchAuth->removeAccessToken($channel);

        $twitchAuth->saveToStorage();
    }
}
