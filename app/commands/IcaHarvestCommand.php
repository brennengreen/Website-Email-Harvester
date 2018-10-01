<?php

namespace Cis\Command;

use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Remote\RemoteWebElement;

class IcaHarvestCommand extends Command
{
    public $icaUrl = 'https://www.icahdq.org/';
    public $directoryBaseUrl = "http://community.icahdq.org";
    protected $redisClient;

    protected function configure()
    {
        $this
            ->setName('harvest:ica')
            ->setDescription('Harvest ica data')
            ->addArgument('username', InputArgument::REQUIRED, 'ICA Username')
            ->addArgument('password', InputArgument::REQUIRED, 'ICA Password')
//            ->addOption('startPage', 'p', InputOption::VALUE_OPTIONAL, 'Page to start on', 1)
        ;
    }

    protected function getRedisClient()
    {
        if ($this->redisClient === null) {
            $this->redisClient = new Client(['database' => 1]);
        }
        return $this->redisClient;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redisClient = $this->getRedisClient();
        $output->writeln("<info>Starting...</info>");
        $this->output = $output;



        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $driver = $this->getDriver();

        $this->login($driver, $username, $password);

        $results = $redisClient->get('cache:ica:step1:results');
        $results = \GuzzleHttp\json_decode($results, true);

        $numProcessed = 0;
        while ($result = array_shift($results)) {
            $id = $result['id'];
            $this->parseMemberInfo($driver, $id, $results);
            $remaining = count($results) + 1;
            $numProcessed += 1;
            $output->writeln('<comment>[*]</comment> Processing member <info>' . $id . '</info> (' . $numProcessed . ' processed, ' .  $remaining. ' left)');
        }

        // ----- STEP 1
//
//        $nextPageElem = null;
//        $results = [];
//        $page = 1;
//        do {
//            $this->goToSearchPage($driver, $nextPageElem);
//            $results = array_merge($results, $this->getResultsFromSearchPage($driver));
//            $nextPageElem = $this->getNextPageOfSearch($driver);
//
//            $redisClient->set('cache:ica:step1:page', $page);
//            $redisClient->set('cache:ica:step1:results', \GuzzleHttp\json_encode($results));
//            $output->writeln('<info>Cached page </info>' . $page);
//
//            $page = $page + 1;
//        } while ($nextPageElem !== null);

        $output->writeln('<info>Done</info>');
//        sleep(10);
//
        $driver->close();
    }

    protected function addToRes(&$res)
    {
        $res = array_merge($res, [count($res) + 1]);
    }

    protected function parseMemberInfo(RemoteWebDriver $driver, $id, &$members)
    {
        $redisCli = $this->getRedisClient();
        // Check if we already have member info

        // If not then get the member details

        // Go to member page
        $this->goToMemberPage($driver, $id);
        // Get member details

        // '#tdLeftColumn + td + td


        // Get list of associates, compare to list of existing members we know of (add to list if not found)
        $assocs = $this->getMemberAssociates($driver, $id);
        foreach ($assocs as $assoc) {
            $id   = $assoc['id'];
            $name = $assoc['name'];
        }
    }

    protected function getMemberInfoFromPage(RemoteWebDriver $driver)
    {
        $infoTable = $driver->findElement(WebDriverBy::cssSelector("#tdLeftColumn + td + td .ViewTable1"));

    }

    protected function getMemberAssociates(RemoteWebDriver $driver, $id)
    {
        $this->goToMemberAssociatePage($driver, $id);


    }

    protected function goToMemberPage(RemoteWebDriver $driver, $id)
    {
        // /members/default.asp?id=
        $driver->get($this->icaUrl . "/members/default.asp?id=" . $id);
    }

    protected function goToMemberAssociatePage(RemoteWebDriver $driver, $id)
    {
        $driver->get($this->icaUrl. "/members/people.asp?list=my_associates&id=" . $id);
    }

    protected function getResultsFromSearchPage(RemoteWebDriver $driver)
    {
        $results = [];
        // [ id => name, ... ]
        // Parse the table!
        $elements = $driver->findElements(WebDriverBy::cssSelector("table#SearchResultsGrid a[id^=MiniProfileLink]"));

        foreach($elements as $element) {
            /** @var RemoteWebElement $element */
            $name = $element->getText();
            $href = $element->getAttribute('href');
            preg_match('/http:\/\/www.icahdq.org\/members\/\?id=(.*)/', $href, $match);
            $memberId = $match[1];
            $results []= [
                'id' => $memberId,
                'name' => $name
            ];
        }
        $this->output->writeln("Finished getting page " . count($results));
        return $results;
    }

    protected function getNextPageOfSearch(RemoteWebDriver $driver)
    {
        // Find the first span (current location) and get the a tag immediately after it
        // tr.DotNetPager td span:first-of-type + a
        try {
            $elem = $driver->findElement(WebDriverBy::cssSelector("tr.DotNetPager td span:first-of-type + a"));
            $this->output->writeln("Got next elem" . $elem->getAttribute('href'));
        } catch (\Exception $e) {
            // Do nothing
            $elem = null;
            $this->output->writeln("Could not get next elem");
            throw $e;
        }

        return $elem;
    }

    protected function goToSearchPage(RemoteWebDriver $driver, $elem = null)
    {
        if (is_null($elem)) {
            $driver->get($this->icaUrl . "/search/newsearch.asp?bst=&cdlGroupID=&txt_country=&txt_statelist=&txt_state=&ERR_LS_20170827_093926_13177=txt_state%7CLocation%7C20%7C0%7C%7C0");
            $driver->switchTo()->frame("SearchResultsFrame");
        } else {
            $elem->click();
        }

        $this->output->writeln("Waiting for that crazy tr.DotNetPager");
        $driver->wait()->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('tr.DotNetPager'))
        );
    }

    /**
     * http://www.icahdq.org/search/newsearch.asp?bst=&cdlGroupID=&txt_country=&txt_statelist=&txt_state=&ERR_LS_20170826_092324_28405=txt_state%7CLocation%7C20%7C0%7C%7C0
     * ParseSearchResultsPage
     *   Get all member id and names from page
     * GetNextPageButton
     *   Find current page
     *   Get next page link (if exists)
     * If next page
     *   Click
     * Else
     *   Save results
     */

    protected function getElementText(RemoteWebElement $element, $cssSelector)
    {
        $result = $element->findElements(WebDriverBy::cssSelector($cssSelector));

        if (count($result) === 0) {
            return '';
        }
        return trim($result[0]->getText());
    }

    protected function getDriver() {
        $seleniumHost = "http://127.0.0.1:4444/wd/hub";
        return RemoteWebDriver::create($seleniumHost, DesiredCapabilities::firefox());
    }

    protected function login(RemoteWebDriver $driver, $username, $password)
    {
        $driver->get($this->icaUrl . 'login.aspx');

        $driver->findElement(WebDriverBy::cssSelector("input[name=u]"))->sendKeys($username);
        $driver->findElement(WebDriverBy::cssSelector("input[name=p]"))->sendKeys($password);
        $driver->findElement(WebDriverBy::cssSelector("input[name=btn_submitLogin]"))->click();

        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("a[href='/Logout.aspx']"))
        );
    }

    /** ------------------------------------------------------------------------------------------------------------ */

    protected function login2(RemoteWebDriver $driver, $username, $password)
    {
        $driver->get($this->icaUrl);

        $driver->findElement(WebDriverBy::cssSelector("input[name=USER_ID]"))->sendKeys($username);
        $driver->findElement(WebDriverBy::cssSelector("input[name=USER_Password]"))->sendKeys($password);
        $driver->findElement(WebDriverBy::cssSelector("input[value=Login]"))->click();

        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("span.loggedIn"))
        );
    }

    protected function execute2(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("<info>Starting...</info>");

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $startPage = $input->getOption('startPage');


        $driver = $this->getDriver();

        $this->login($driver, $username, $password);

        $nextPageUrl = $this->directoryBaseUrl . "/ohana/directory/index.cfm?templatemode=user&q=*&c=full_name&gpg=$startPage&grc=100";

        $output->writeln('Name, Email, Company, Title, Address');

        while($nextPageUrl) {
            $driver->get($nextPageUrl);

            $results = $driver->findElements(WebDriverBy::cssSelector("ul.gridResults li.result"));
            foreach($results as $result) {
                /** @var RemoteWebElement $result */
                $name    = $this->getElementText($result, "div.colRight h2");
                $company = $this->getElementText($result, "li.company");
                $title   = $this->getElementText($result, "li.title");
                $email   = $this->getElementText($result, "li.email a");
                $address = $this->getElementText($result, "li.ad1_full_address");

                $output->writeln(sprintf("%s,%s,%s,\"%s\",\"%s\"", $name, $email, $company, $title, $address));
            }
            try {
                $nextPageUrl = trim($driver->findElement(WebDriverBy::cssSelector("a[title=\"Next Page\"]"))->getAttribute('href'));
            } catch (\Exception $e) {
                $nextPageUrl = null;
            }
            sleep(rand(4, 9));
        }

        sleep(10);

        $driver->close();
    }

}
