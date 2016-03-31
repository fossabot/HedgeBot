<?php
namespace HedgeBot\Plugins\Currency;

use HedgeBot\Core\HedgeBot;
use HedgeBot\Core\Plugins\Plugin as PluginBase;
use HedgeBot\Core\Plugins\PropertyConfigMapping;
use HedgeBot\Core\API\Server;
use HedgeBot\Core\API\Plugin;
use HedgeBot\Core\API\IRC;

/**
 * Currency plugin
 * Holds a currency system on the bot, per channel.
 *
 * Configuation vars:
 *
 * - currencyName: Currency singular name (default: coin)
 * - currencyNamePlural: Currency plural name (default: coins)
 * - statusCommand: The command the bot will respond to display user status (default: coins)
 * - statusMessage: The message to be shown when the status command is requested. Message vars:
 * 		* @name: The name of the person who requested the message.
 * 		* @total: The current total of his/her account.
 * 		* @currency: The currency name. Plural form is computed automatically.
 * - initialAmount: The initial amount each viewer/chatter is given when initially joining the chat.
 *
 * These config vars are definable in a global manner using the config namespace "plugin.Currency",
 * and per-channel, using the config namespaces "plugin.Currency.channel.<channel-name>". If one config parameter
 * misses from the per-channel config, then it is taken from the global config.
 * It is advised to define both, to avoid having situations where the default ones are used.
 */
class Currency extends PluginBase
{
	private $accounts = array(); // Accounts, by channel
	private $activityTimes = array(); // Last activity times for channels users
	private $giveTimes = array(); // Last times money was given to users

	// Plugin configuration variables by channel
	private $currencyName = array(); // Money names
	private $currencyNamePlural = array(); // Money plural name
	private $statusCommand = array(); // Money status command names
	private $statusMessage = array(); // Money status command message
	private $initialAmount = array(); // Initial money amount
	private $giveInterval = array(); // Money giving interval
	private $giveAmount = array(); // Money giving amount
	private $timeoutThreshold = array(); // Money giving timeout threshold

	// Global plugin configuration variables, used if no money name is overridden for the channel
	private $globalCurrencyName; // Global money name
	private $globalCurrencyNamePlural; // Global money plural name
	private $globalStatusCommand; // Global money command
	private $globalStatusMessage; // Global money message
	private $globalInitialAmount; // Global initial money amount
	private $globalGiveInterval; // Global money giving interval
	private $globalGiveAmount; // Global money giving amout
	private $globalTimeoutThreshold; // Global money giving timeout threshold, by channel

	const DEFAULT_CURRENCY_NAME = 'coin';
	const DEFAULT_CURRENCY_NAME_PLURAL = 'coins';
	const DEFAULT_STATUS_COMMAND = 'coins';
	const DEFAULT_STATUS_MESSAGE  = '@name, you currently have @total @currency';
	const DEFAULT_INITIAL_AMOUNT = 0;
	const DEFAULT_GIVE_INTERVAL = 120;
	const DEFAULT_GIVE_AMOUNT = 5;
	const DEFAULT_TIMEOUT_THRESHOLD = 1800;

	// Traits
	use PropertyConfigMapping;

	/** Plugin initialization */
	public function init()
	{
		if(!empty($this->data->accounts))
			$this->accounts = $this->data->accounts->toArray();

		$this->reloadConfig();

        Plugin::getManager()->addRoutine($this, 'RoutineAddMoney', 10);
	}

	public function SystemEventConfigUpdate()
	{
		$this->config = HedgeBot::getInstance()->config->get('plugin.Currency');
		$this->reloadConfig();
	}

	/** Add money to guys standing on the chat for a certain time */
	public function RoutineAddMoney()
	{
		$currentTime = time();

		foreach($this->activityTimes as $channel => $channelTimes)
		{
			// Get the good interval config value
			$giveInterval = $this->getConfigParameter($channel, 'giveInterval');

			// Check that the give interval between money giving is elapsed, if not, go to next iteration
			if(!empty($this->giveTimes[$channel]) && $this->giveTimes[$channel] + $giveInterval > $currentTime)
				continue;

			// Get configuration settings
			$timeoutThreshold = $this->getConfigParameter($channel, 'timeoutThreshold');
			$giveAmount = $this->getConfigParameter($channel, 'giveAmount');

			// Setting any configuration value to specifically 0 skips giving money
			if((!empty($this->giveAmount[$channel]) && $this->giveAmount[$channel] === 0) || $giveAmount === 0)
				continue;

			// Finally, giving to people their money
			foreach($channelTimes as $name => $time)
			{
				if($time + $timeoutThreshold > $currentTime)
					$this->accounts[$channel][$name] += $giveAmount;
			}

			// Aand saving the accounts
			$this->data->set('accounts', $this->accounts);
		}
	}

	/** Initializes an account on join */
	public function ServerJoin($command)
	{
		if(!empty($this->accounts[$command['channel']][$command['nick']]))
			return;

		$serverConfig = Server::getConfig();
		if(strtolower($command['nick']) == strtolower($serverConfig['name']))
		{
			$this->activityTimes[$command['channel']] = array();
			$this->giveTimes[$command['channel']] = time();
			return;
		}

		$initialAmount = $this->getConfigParameter($command['channel'], 'initialAmount');

		$this->accounts[$command['channel']][$command['nick']] = $initialAmount;
		$this->data->set('accounts', $this->accounts);
	}

	/**
	 * Initializes accounts for user that talk before the join notice comes in.
	 * Handles status command calls too.
	 * Updates last activity time for the user.
	 */
	public function ServerPrivmsg($command)
	{
		$this->ServerJoin($command);

		$cmd = explode(' ', $command['message']);
		if($cmd[0][0] == '!')
		{
			$cmd = substr($cmd[0], 1);
			$statusCommand = $this->getConfigParameter($command['channel'], 'statusCommand');
			if($statusCommand == $cmd)
				$this->RealCommandAccount($command, array());
		}

		$this->activityTimes[$command['channel']][$command['nick']] = time();
	}

	/** Mod function: adds a given amount of money to a given player */
	public function CommandGive($param, $args)
	{
		// Check rights
		if(!$param['moderator'])
			return;

		// Check that arguments are there
		if(count($args) < 2)
			return IRC::message($param['channel'], 'Insufficient parameters.');

		// Lowercasing the username
		$nick = strtolower($args[0]);

		// Check that the account exists
		if(!isset($this->accounts[$param['channel']][$nick]))
			return IRC::message($param['channel'], 'Unknown user.');

		$this->accounts[$param['channel']][$nick] += (int) $args[1];
		$this->data->set('accounts', $this->accounts);
	}

	/** Mod function: show another user's status */
	public function CommandCheck($param, $args)
	{
		if(!$param['moderator'])
			return;

		$channelUsers = IRC::getChannelUsers($param['channel']);
		if(isset($this->accounts[$param['channel']][$nick]))
		{
			$message = $this->formatMessage("Admin check(@name): @total @currency", $param['channel'], $param['nick']);
			IRC::message($param['channel'], $message);
		}
	}

	/** Mod function: removes a given amount of money from a given player */
	public function CommandTake($param, $args)
	{
		// Check rights
		if(!$param['moderator'])
			return;

		// Check that arguments are there
		if(count($args) < 2)
			return IRC::message($param['channel'], 'Insufficient parameters.');

		// Lowercasing the username
		$nick = strtolower($args[0]);

		// Check that the account exists
		if(!isset($this->accounts[$param['channel']][$nick]))
			return IRC::message($param['channel'], 'Unknown user.');

		// Perform account operations
		$sum = (int) $args[1];

		if($this->accounts[$param['channel']][$nick] - $sum > 0)
			$this->accounts[$param['channel']][$nick] -= $sum;
		else
			$this->accounts[$param['channel']][$nick] = 0;

		$this->data->set('accounts', $this->accounts);
	}

	/** Real account show command, shows the current amount of currency for the user */
	public function RealCommandAccount($param, $args)
	{
		$message = $this->getConfigParameter($param['channel'], 'statusMessage');
		IRC::message($param['channel'], $this->formatMessage($message, $param['channel'], $param['nick']));
	}

	/** Reloads configuration variables */
	public function reloadConfig()
	{
		$parameters = array('currencyName',
							'currencyNamePlural',
							'statusCommand',
							'statusMessage',
							'initialAmount',
							'giveInterval',
							'giveAmount',
							'timeoutThreshold');

		$this->globalCurrencyName = self::DEFAULT_CURRENCY_NAME;
		$this->globalCurrencyNamePlural = self::DEFAULT_CURRENCY_NAME_PLURAL;
		$this->globalStatusCommand = self::DEFAULT_STATUS_COMMAND;
		$this->globalStatusMessage = self::DEFAULT_STATUS_MESSAGE;
		$this->globalInitialAmount = self::DEFAULT_INITIAL_AMOUNT;
		$this->globalGiveInterval = self::DEFAULT_GIVE_INTERVAL;
		$this->globalGiveAmount = self::DEFAULT_GIVE_AMOUNT;
		$this->globalTimeoutThreshold = self::DEFAULT_TIMEOUT_THRESHOLD;

		$this->mapConfig($this->config, $parameters);
	}

	/** Formats a currency message, with plural forms and everything. */
	private function formatMessage($message, $channel, $name)
	{
		if(!empty($this->currencyName[$channel]))
		{
			$currencyName = $this->currencyName[$channel];
			$currencyNamePlural = $this->currencyNamePlural[$channel];
		}
		else
		{
			$currencyName = $this->globalCurrencyName;
			$currencyNamePlural = $this->globalCurrencyNamePlural;
		}

		$message = str_replace(	array(	'@name',
										'@total',
										'@currency'),
								array(	$name,
										$this->accounts[$channel][$name],
										$this->accounts[$channel][$name] > 1 ?
											$currencyNamePlural
										:	$currencyName),
								$message);

		return $message;
	}
}
