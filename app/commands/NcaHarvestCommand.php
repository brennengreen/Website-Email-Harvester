<?php

namespace Cis\Command;

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
    private $loginUrl = 'https://www.natcom.org/ncalogin.aspx';

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

       $idx = array_search($groupId, array_column($this->interestGroups, 'id'));

       if ($idx === null) {
           $output->writeln("<error>\n\n  Cannot find group by ID: {$groupId}\t\n");
           return;
       }
       $output->writeln("<info>Starting</info>");


       $driver = $this->getDriver();
       $this->login($driver, $username, $password);

       $members = [];
       $numGroups = count($this->interestGroups);

       while($idx < $numGroups-1) {
           $currentGroup = $this->interestGroups[$idx];
           $output->writeln("Processing [<info>{$currentGroup['id']}</info>] <comment>${currentGroup['name']}</comment>");

           $members = $this->getMemberDataForGroup($driver, $currentGroup['id'], $members);

           $tmpFile = fopen("/tmp/nca.json", "w");
           fputs($tmpFile, json_encode($members));
           fclose($tmpFile);

           $this->outputToMemberFile($members, $filename);

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
                $member['phone'],
                $member['fax'],
                implode(',', $member['groups'])
            ]);
        }

        fclose($file);
    }

    protected function getMemberDataForGroup(RemoteWebDriver $driver, $groupId, $memberData)
    {
        $driver->get("https://www.natcom.org/Member-Directory");

        // Select the 'Search By Interest Group' tab
        $driver
            ->findElement(WebDriverBy::cssSelector('a[aria-controls="NCA_Interest_Group"]'))
            ->click();

        // The group dropdown
        $select = new WebDriverSelect(
            $driver->findElement(WebDriverBy::cssSelector("select[name$=\"directory_nca\"]"))
        );
        $select->selectByValue($groupId);

        $driver
            ->findElement(WebDriverBy::cssSelector("input[name$=btnSearchByMembershipUnit][type=submit]"))
            ->click();

        // Wait for the member table to be present
        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("table[id$=grdPerson]"))
        );

        $rows = $driver->findElements(WebDriverBy::cssSelector("table[id$=grdPerson] td a"));
        $memberUrls = [];
        foreach($rows as $row) {
            /** @var RemoteWebElement $row */
            $url = $row->getAttribute('href');
            // Url looks like: https://www.natcom.org/Directories/PersonListing.aspx?ID=hlr8qPVxeYA%3d
            $memberId = preg_replace("/.*ID=(.*)$/i", "$1", $url);
            if (isset($memberData[$memberId])) {
                // We already have the data for the member, so just add that the person is in this group
                $memberData[$memberId]['groups'] []= $groupId;
            } else {
                $memberUrls []= [
                    'id' => $memberId,
                    'url' => $url
                ];
            }
        }

        foreach ($memberUrls as $memberUrl) {
            $memberData[$memberUrl['id']] = $this->getMemberDataFromUrl($driver, $memberUrl['url']);
            // Add that this member is in this group
            $memberData[$memberUrl['id']]['groups'] = [ $groupId ];
            sleep(rand(1,3));
        }

        return $memberData;

    }

    protected function getMemberDataFromUrl(RemoteWebDriver $driver, $url)
    {
        $driver->get($url);

        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("table[id$=PersonListing_tblMain]"))
        );
        $tableElement = $driver->findElement(WebDriverBy::cssSelector("table[id$=PersonListing_tblMain]"));

        $name    = $this->getElementText($tableElement, "span[id$=lblPersonName]");
        $company = $this->getElementText($tableElement, "span[id$=lblCompanyName]");
        $title   = $this->getElementText($tableElement, "span[id$=lblPersonTitle]");
        $address = $this->getElementText($tableElement, "span[id$=lblAddress]");
        $email   = $this->getElementText($tableElement, "span[id$=lblEmail]");
        $phone   = $this->getElementText($tableElement, "span[id$=lblPhone]");
        $fax     = $this->getElementText($tableElement, "span[id$=lblFax]");

        return [
            'name'    => $name,
            'company' => $company,
            'title'   => $title,
            'address' => $address,
            'email'   => $email,
            'phone'   => $phone,
            'fax'     => $fax,
        ];
    }

    protected function getElementText(RemoteWebElement $element, $cssSelector)
    {
        $result = $element->findElements(WebDriverBy::cssSelector($cssSelector));

        if (count($result) === 0) {
            return '';
        }
        return trim($result[0]->getText());
    }

    protected function login(RemoteWebDriver $driver, $username, $password)
    {
        $driver->get($this->loginUrl);

        $driver->findElement(WebDriverBy::cssSelector("input[name$=UserID]"))->sendKeys($username);
        $driver->findElement(WebDriverBy::cssSelector("input[name$=Password]"))->sendKeys($password);
        $driver->findElement(WebDriverBy::cssSelector("input[name$=Login][type=Submit]"))->click();

        // Wait until the logout button shows up
        $driver->wait(30, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector("a[id$=cmdLogout]"))
        );
    }

    protected function getDriver() {
        $seleniumHost = "http://127.0.0.1:4444/wd/hub";
        return RemoteWebDriver::create($seleniumHost, DesiredCapabilities::firefox());
    }

    private $interestGroups = [
		['id' => 1134, 'name' => "Activism and Social Justice Division"],
		['id' => 6, 'name' => "African American Communication & Culture Division"],
		['id' => 7, 'name' => "American Studies Division"],
		['id' => 2, 'name' => "Applied Communication Division"],
		['id' => 26, 'name' => "Argumentation and Forensics Division"],
		['id' => 3, 'name' => "Asian/Pacific American Caucus"],
		['id' => 4, 'name' => "Asian/Pacific American Communication Studies Division"],
		['id' => 9, 'name' => "Basic Course Division"],
		['id' => 10, 'name' => "Black Caucus"],
		['id' => 28, 'name' => "Caucus on Lesbian, Gay, Bisexual, Transgender and Queer Concerns"],
		['id' => 16, 'name' => "Communication and Aging Division"],
		['id' => 15, 'name' => "Communication and Law Division"],
		['id' => 35, 'name' => "Communication and Social Cognition Division"],
		['id' => 1135, 'name' => "Communication and Sport Division"],
		['id' => 14, 'name' => "Communication and the Future Division"],
		['id' => 632, 'name' => "Communication as Social Construction Division"],
		['id' => 8, 'name' => "Communication Assessment Division"],
		['id' => 406, 'name' => "Communication Centers Section"],
		['id' => 19, 'name' => "Communication Ethics  Division"],
		['id' => 12, 'name' => "Community College Section"],
		['id' => 13, 'name' => "Critical & Cultural Studies Division"],
		['id' => 58, 'name' => "Disability Issues Caucus"],
		['id' => 1150, 'name' => "Economics, Communication and Society Division"],
		['id' => 22, 'name' => "Elementary and Secondary Educational Section"],
		['id' => 20, 'name' => "Emeritus and Retired Members Section"],
		['id' => 21, 'name' => "Environmental Communication Division"],
		['id' => 23, 'name' => "Ethnography Division"],
		['id' => 24, 'name' => "Experiential Learning in Communication Division"],
		['id' => 25, 'name' => "Family Communication Division"],
		['id' => 57, 'name' => "Feminist and Women's Studies Division"],
		['id' => 27, 'name' => "Freedom of Expression Division"],
		['id' => 1136, 'name' => "Game Studies Division"],
		['id' => 29, 'name' => "GLBTQ Communication Studies Division"],
		['id' => 30, 'name' => "Group Communication Division"],
		['id' => 31, 'name' => "Health Communication Division"],
		['id' => 32, 'name' => "Human Communication and Technology Division"],
		['id' => 34, 'name' => "Instructional Development Division"],
		['id' => 33, 'name' => "International and Intercultural Communication Division"],
		['id' => 36, 'name' => "Interpersonal Communication Division"],
		['id' => 38, 'name' => "La Raza Caucus"],
		['id' => 37, 'name' => "Language and Social Interaction Division"],
		['id' => 39, 'name' => "Latino and Latina Communication Studies Division"],
		['id' => 40, 'name' => "Mass Communication Division"],
		['id' => 631, 'name' => "Master's (College and University) Education Section"],
		['id' => 509, 'name' => "Nonverbal Communication Division"],
		['id' => 41, 'name' => "Organizational Communication Division"],
		['id' => 44, 'name' => "Peace and Conflict Communication Division"],
		['id' => 46, 'name' => "Performance Studies Division"],
		['id' => 50, 'name' => "Philosophy of Communication Division"],
		['id' => 43, 'name' => "Political Communication Division"],
		['id' => 42, 'name' => "Public Address Division"],
		['id' => 1137, 'name' => "Public Dialogue and Deliberation Division"],
		['id' => 45, 'name' => "Public Relations Division"],
		['id' => 47, 'name' => "Rhetorical and Communication Theory Division"],
		['id' => 49, 'name' => "Spiritual Communication  Division"],
		['id' => 52, 'name' => "Student Section"],
		['id' => 53, 'name' => "Theatre, Film and New Multi-Media Division"],
		['id' => 54, 'name' => "Training and Development Division"],
		['id' => 48, 'name' => "Undergraduate College and University Section"],
		['id' => 55, 'name' => "Visual Communication Division"],
		['id' => 56, 'name' => "Women's Caucus"],
    ];



}

