<?php

namespace Cis\Command;

use Facebook\WebDriver\JavaScriptExecutor;
use Facebook\WebDriver\WebDriverSelect;
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

class NcaHarvestCommand extends Command
{
    private $loginUrl = 'https://portal.natcom.org/account/login.aspx?RedirectUrl=https://www.natcom.org/sso?destination_internal=/&reload=timezone';

    protected function configure()
    {
        $this
            ->setName('harvest:nca')
            ->setDescription('Harvest nca data')
           ->addArgument('username', InputArgument::REQUIRED, 'NCA Username')
           ->addArgument('password', InputArgument::REQUIRED, 'NCA Password')
           ->addOption('output', 'o', InputArgument::OPTIONAL, 'Output file', 'nca.csv')
           ->addOption('groupId', 'g', InputArgument::OPTIONAL, 'Group ID to START at (see list in this command class)', 1134)
           ->addOption('startPage', 'p', InputOption::VALUE_OPTIONAL, 'Page to start on', 1)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // $contents = file_get_contents("nca.json");
        // $data = json_decode($contents, true);
        // $this->outputToMemberFile($data, "nca-3.csv");

       $username = $input->getArgument('username');
       $password = $input->getArgument('password');

       $groupId = $input->getOption('groupId');
       $filename = $input->getOption('output');

       $idx = 0;

       if ($idx === null) {
           $output->writeln("<error>\n\n  Cannot find group by ID: {$groupId}\t\n");
           return;
       }
       $output->writeln("<info>Starting</info>");


       $driver = $this->getDriver();
       $this->login($driver, $username, $password);
       $driver->get("https://portal.natcom.org/member-directory");



        $members = [];
       $numGroups = count($this->interestGroups);

       while($idx < $numGroups-1) {
           $currentGroup = $this->interestGroups[$idx];
           $output->writeln("Processing [<info>{$currentGroup['name']}</info>] <comment>${currentGroup['name']}</comment>");

           $members = $this->getMemberDataForGroup($driver, $currentGroup, $members);
           $idx++;
       }

       $driver->close();
    }

    protected function outputToMemberFile($members, $filename)
    {
        $file = fopen($filename, "w");

        $headers = [
            'id',
            'email',
            'name',
            'title',
            'company',
            'address',
            'phone',
            'fax',
            'groups'
        ];

        fputcsv($file, $headers);

        foreach ($members as $id => $member) {
            fputcsv($file, [
                $id,
                $member['email'],
                $member['name'],
                $member['title'],
                $member['company'],
                $member['address'],
                implode(',', $member['groups'])
            ]);
        }

        fclose($file);
    }

    protected function getMemberDataForGroup(RemoteWebDriver $driver, $group, $memberData)
    {
        // Wait until the search button has appeared to start crawling 
        $driver->wait(10, 500)->until(
          WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlQuery_btnSearch\"]"))
        );

        // Select the check box for the current member group
        $driver->executeScript("arguments[0].click();", [$driver->findElement(WebDriverBy::cssSelector("#main_content_ctl07_ctlQuery_lst68d08db15f2145d588aec65f6a092330_pnlMultiSelect > ul > li:nth-child(" . $group["selectorIdx"] . ") > div > label"))]);

        // Click the search button to create a query for the current interest group
        $driver->wait(5, 500)->until(
            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlQuery_btnSearch\"]"))
        );
        $driver->executeScript("arguments[0].click();", [$driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlQuery_btnSearch\"]"))]);


        $currentPage = 0; // Current page of results we're on
        $nextPageFound = true; // Is there a next page to scrape results from?


        $driver->executeScript('window.scrollTo(0, 0)'); // Scroll back up to the top to restart the process
        while($nextPageFound) { // Until there no more pages of results to scrape, keep repeating the scraping process
            $driver->wait(20, 500)->until( // Wait until results load
                WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("#main_content_Content > div.col-md-12.content-banner-main > div.grid-container > ul"))
            );
            $memberData[] = $this->scrapePage($driver, $group["name"]); // Scrape the results off the page

            try { // Try to locate the button for the next page, if its never found -> nextPageFound = false
                $driver->wait(10, 500)->until (
                    WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlListControl_lnkPager" . ($currentPage + 1) ."\"]"))
                );
                $nextPageFound = true;
            } catch (\Exception $exception) {
                $nextPageFound = false;
            }

            if (!$nextPageFound) {
                try { // Try to locate the button for the next page, if its never found -> nextPageFound = false
                    $driver->wait(10, 500)->until (
                        WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlListControl_lnkPagerNext\"]"))
                    );
                    $nextPageFound = true;
                } catch (\Exception $exception) {
                    $nextPageFound = false;
                }
                if ($nextPageFound) {
                    $driver->executeScript("arguments[0].click();", [$driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlListControl_lnkPagerNext\"]"))]);
                    $currentPage++;
                }
            } else {
                $driver->wait(10, 500)->until(
                    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlListControl_lnkPager" . ($currentPage + 1) ."\"]"))
                );

                sleep(10);
                $driver->executeScript("arguments[0].click();", [$driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_ctl07_ctlListControl_lnkPager" . ($currentPage + 1) ."\"]"))]);
                $currentPage++;
            }


            echo "\n\n";
        }
        
        // Return to the original page and restart the process
        $driver->get("https://portal.natcom.org/member-directory");
        $driver->executeScript("arguments[0].click();", [$driver->findElement(WebDriverBy::cssSelector("#main_content_ctl07_ctlQuery_lst68d08db15f2145d588aec65f6a092330_pnlMultiSelect > ul > li:nth-child(" . $group["selectorIdx"] . ") > div > label"))]);

        return $memberData;

    }

    protected function scrapePage(RemoteWebDriver $driver, $group) {
        $results = $driver->findElements(WebDriverBy::className("list-result"));
        $membersInfo = [];

        foreach($results as $result) {
            /** @var RemoteWebElement $row */
            $memberResult = [];
            try {
                $divs = $result->findElements(WebDriverBy::tagName("span"));
                $memberResult["name"] = $divs[0]->getText();
                $memberResult["title"] = $divs[1]->getText();
                $memberResult["institution"] = $divs[2]->getText();
                $memberResult["email"] = $divs[3]->getText();
                $memberResult["address"] = $divs[4]->getText();
                $memberResult["group"] = $group;
            } catch (\Exception $exception) {
                echo "SKIP";
            }

            $membersInfo[] = $memberResult;
            echo "\n" . $memberResult["name"] . "%" . $memberResult["title"] . "%" . $memberResult["institution"] . "%" . $memberResult["email"] . "%" . $memberResult["address"] . "%" . $memberResult["group"] . "\n" ;
        }

        return $membersInfo;
    }

    protected function login(RemoteWebDriver $driver, $username, $password)
    {
        $driver->get($this->loginUrl);

        $driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_Login_txtLoginUserName\"]"))->sendKeys($username);
        $driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_Login_txtLoginPassword\"]"))->sendKeys($password);
        $driver->findElement(WebDriverBy::xpath("//*[@id=\"main_content_Login_LoginButton\"]"))->click();

        // Wait until the logout button shows up
        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath("//*[@id=\"top-bar\"]/div/div/div[1]/nav/ul/li[3]/a"))
        );
    }

    protected function getDriver() {
        $seleniumHost = "http://127.0.0.1:4444/wd/hub";
        return RemoteWebDriver::create($seleniumHost, DesiredCapabilities::chrome());
    }

    private $interestGroups = [
		/*['id' => 1134, 'name' => "Activism and Social Justice Division", 'selectorIdx' => 1],
		['id' => 6, 'name' => "African American Communication & Culture Division", 'selectorIdx' => 2],
		['id' => 7, 'name' => "American Studies Division", 'selectorIdx' => 3],
		['id' => 2, 'name' => "Applied Communication Division", 'selectorIdx' => 4],
		['id' => 26, 'name' => "Argumentation and Forensics Division", 'selectorIdx' => 5],
		['id' => 3, 'name' => "Asian/Pacific American Caucus", 'selectorIdx' => 6],
		['id' => 4, 'name' => "Asian/Pacific American Communication Studies Division", 'selectorIdx' => 7],
		['id' => 9, 'name' => "Basic Course Division", 'selectorIdx' => 8],
		['id' => 10, 'name' => "Black Caucus", 'selectorIdx' => 9],
		['id' => 28, 'name' => "Caucus on Lesbian, Gay, Bisexual, Transgender and Queer Concerns", 'selectorIdx' => 10],
		['id' => 16, 'name' => "Communication and Aging Division", 'selectorIdx' => 11],
		['id' => 15, 'name' => "Communication and Law Division", 'selectorIdx' => 12],
		['id' => 35, 'name' => "Communication and Social Cognition Division", 'selectorIdx' => 13],
		['id' => 1135, 'name' => "Communication and Sport Division", 'selectorIdx' => 14],*/
		['id' => 14, 'name' => "Communication and Social Cognition",'selectorIdx' => 15],
		['id' => 632, 'name' => "Critical and Cultural Studies Division", 'selectorIdx' => 16],
		['id' => 8, 'name' => "Economics, Communication, and Society Division", 'selectorIdx' => 17],
		['id' => 406, 'name' => "Environmental Communication Division", 'selectorIdx' => 18],
		['id' => 19, 'name' => "Ethnography Division", 'selectorIdx' => 19],
		['id' => 12, 'name' => "Experiential Learning in Communication Division", 'selectorIdx' => 20],
		['id' => 13, 'name' => "Family Communication Division", 'selectorIdx' => 21],
		['id' => 58, 'name' => "Feminist and Women Studies Division", 'selectorIdx' => 22],
		['id' => 1150, 'name' => "Freedom of Expression Division", 'selectorIdx' => 23],
		['id' => 22, 'name' => "Game Studies Division", 'selectorIdx' => 24],
		['id' => 20, 'name' => "GLBTQ Comm Studies Division", 'selectorIdx' => 25],
		['id' => 21, 'name' => "Group Communication Division", 'selectorIdx' => 26],
		['id' => 23, 'name' => "Health Communication Division", 'selectorIdx' => 27],
		['id' => 24, 'name' => "Human Communication and Technology Division", 'selectorIdx' => 28],
		['id' => 25, 'name' => "Instructional Development Division", 'selectorIdx' => 29],
		['id' => 57, 'name' => "International and Intercultural Communication Division", 'selectorIdx' => 30],
		['id' => 27, 'name' => "Interpersonal Communication Division", 'selectorIdx' => 31],
		['id' => 1136, 'name' => "Language and Social Interaction Division", 'selectorIdx' => 32],
		['id' => 29, 'name' => "Latino/Latina Communication Studies Division", 'selectorIdx' => 33],
		['id' => 30, 'name' => "Mass Communication Division", 'selectorIdx' => 34],
		['id' => 31, 'name' => "Organizational Communication Division", 'selectorIdx' => 35],
		['id' => 32, 'name' => "Peace and Conflict Communication Division", 'selectorIdx' => 36],
		['id' => 34, 'name' => "Performance Studies Division", 'selectorIdx' => 37],
		['id' => 33, 'name' => "Philosophy of Communication Division", 'selectorIdx' => 38],
		['id' => 36, 'name' => "Political Communication Division", 'selectorIdx' => 39],
		['id' => 38, 'name' => "Public Address Division", 'selectorIdx' => 40],
		['id' => 37, 'name' => "Language and Social Interaction Division", 'selectorIdx' => 41],
		['id' => 39, 'name' => "Latino and Latina Communication Studies Division", 'selectorIdx' => 42],
		['id' => 40, 'name' => "Mass Communication Division", 'selectorIdx' => 43],
		['id' => 631, 'name' => "Master's (College and University) Education Section", 'selectorIdx' => 44],
		['id' => 509, 'name' => "Nonverbal Communication Division", 'selectorIdx' => 45],
		['id' => 41, 'name' => "Organizational Communication Division", 'selectorIdx' => 46],
		['id' => 44, 'name' => "Peace and Conflict Communication Division", 'selectorIdx' => 47],
		['id' => 46, 'name' => "Performance Studies Division", 'selectorIdx' => 48],
		['id' => 50, 'name' => "Philosophy of Communication Division", 'selectorIdx' => 49],
		['id' => 43, 'name' => "Political Communication Division", 'selectorIdx' => 50],
		['id' => 42, 'name' => "Public Address Division", 'selectorIdx' => 51],
		['id' => 1137, 'name' => "Public Dialogue and Deliberation Division", 'selectorIdx' => 52],
		['id' => 45, 'name' => "Public Relations Division", 'selectorIdx' => 53],
		['id' => 47, 'name' => "Rhetorical and Communication Theory Division", 'selectorIdx' => 54],
		['id' => 49, 'name' => "Spiritual Communication  Division", 'selectorIdx' => 55],
		['id' => 52, 'name' => "Theatre, Film, and New Multi-Media Division", 'selectorIdx' => 56],
		['id' => 53, 'name' => "Training and Development Division", 'selectorIdx' => 57],
    ];



}