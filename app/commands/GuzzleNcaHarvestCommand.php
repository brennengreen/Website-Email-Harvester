<?php

namespace Cis\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;


class GuzzleNcaHarvestCommand extends Command
{
    private $loginUrl = 'https://www.natcom.org/ncalogin.aspx';
    /** @var InputInterface $input */
    private $input;
    /** @var OutputInterface $output */
    private $output;

    protected function configure()
    {
        $this
            ->setName('guzzle:harvest:nca')
            ->setDescription('Harvest nca data (guzzle')
            ->addArgument('username', InputArgument::REQUIRED, 'NCA Username')
            ->addArgument('password', InputArgument::REQUIRED, 'NCA Password')
            ->addOption('output', 'o', InputArgument::OPTIONAL, 'Output file', 'nca.csv')
            ->addOption('groupId', 'g', InputArgument::OPTIONAL, 'Group ID to START at (see list in this command class)', 1134)
            ->addOption('startPage', 'p', InputOption::VALUE_OPTIONAL, 'Page to start on', 1)
        ;
    }

    protected $header;
    protected $table;
    private $idLUT;
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
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

//        $this->login($username, $password);

        $members = [];
        $numGroups = count($this->interestGroups);
        $client = $this->getClient();
//
        $this->header = [];
        $this->table  = [];
        $this->idLUT  = [];
//        $crawler = new Crawler(file_get_contents("/tmp/nca_1134.xml"));
//        $data = simplexml_load_string($crawler->filter('string')->first()->text());
//        $data = \GuzzleHttp\json_encode($xml);
//        $data = \GuzzleHttp\json_decode($json, true);
//        var_dump($data);
//
//        die;

        while($idx < $numGroups-1) {
            $currentGroup = $this->interestGroups[$idx];

//            $resp = $client->request("POST", "https://ams.natcom.org/webservices/api/apiservice.asmx/directoryGetByGroup", [
//                'form_params' => [
//                    'GroupID' => $currentGroup['id'],
//                    'authenticationtoken' => $password,
//                ]
//            ]);
            $output->writeln("Processing [<info>{$currentGroup['id']}</info>] <comment>${currentGroup['name']}</comment>");


//            $tmpFile = fopen("/tmp/nca_".$currentGroup['id'].".xml", "w");
//            fputs($tmpFile, $resp->getBody()->getContents());
//            fclose($tmpFile);

//            sleep(rand(1,3));

            $this->parseXMLResults($currentGroup['id']);
            $idx++;
        }

        $this->writeToCSV();

        $output->writeln("<info>Done</info>");
    }

    protected function writeToCSV()
    {
        $fp = fopen('nca.csv', 'w');
        fputcsv($fp, $this->header);

        foreach ($this->table as $row) {
            $outrow = [];
            foreach ($this->header as $idx => $key) {
                $outrow[$idx] = null;
                if (isset($row[$idx])) {
                    $outrow[$idx] = $row[$idx];
                }
            }
            $outrow[]=join("|", $row['groups']);
//            $row['groups'] = join("|", $row['groups']);
            fputcsv($fp, $outrow);
        }

        fclose($fp);
    }

    protected function parseXMLResults($id) {
        $crawler = new Crawler(file_get_contents("/tmp/nca_" . $id . ".xml"));
        $data = simplexml_load_string($crawler->filter('string')->first()->text());
        $data = \GuzzleHttp\json_encode($data);
        $data = \GuzzleHttp\json_decode($data, true);

        foreach ($data['Member'] as $member) {
            $row = [];
            foreach($member as $key => $value) {
                $idx = array_search($key, $this->header);
                if (false === $idx) {
                    $this->header []= $key;
                    $idx = count($this->header) - 1;
                }
                if (is_array($value)) {
                    var_dump($value);
                    $row[$idx] = null;
                } else {
                    $row[$idx] = $value;
                }
            }

            if (isset($this->table[$row[0]])) {
                $this->table[$row[0]]['groups'] []= $id;
            } else {
                $row['groups'] = [$id];
                $this->table[$row[0]] = $row;
            }
        }
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

    /**
     * @return Client
     */
    protected function getClient()
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $this->client = new Client([
            'base_uri' => 'https://www.natcom.org/',
            'cookies' => true
        ]);

        // Returns guzzle client
        return $this->client;
    }

    protected function login($username, $password)
    {
        $this->output->writeln("<info>Logging in as " . $username);
        $client = $this->getClient();
        $formDetails = $this->getLoginFormDetails();

        $formData = [
            $formDetails['user_field'] => $username,
            $formDetails['pass_field'] => $password,
        ];
        foreach ($formDetails['extra_fields'] as $name => $value) {
            $formData[$name] = $value;
        }

        // LOGIN
        $client->post($formDetails['action'], [
            'form_params' => $formData
        ]);


        $client->post("https://ams.natcom.org/webservices/api/apiservice.asmx/directoryGetByGroup", [
            'form_params' => [

            ]
        ]);
//        $resp = $client->get('/My-Profile');
//
//        echo $resp->getBody()->getContents();
//
//        $resp = $client->get("https://ams.natcom.org/ams2/directory.aspx");
//
//        echo $resp->getBody()->getContents();
    }

    protected function getLoginFormDetails()
    {
        $client = $this->getClient();
        $loginForm = [
            'action' => '/user/login',
            'user_field' => 'name',
            'pass_field' => 'pass',
            'extra_fields' => [],
        ];

        $response = $client->get($loginForm['action']);

        $crawler = new Crawler($response->getBody()->getContents());
        $formEl = $crawler->filter('form[id=user-login]');

        // Make sure we use the right action
        $loginForm['action'] = $formEl->attr("action");

        $formEl->filter('input')->each(function(Crawler $node, $i) use (&$loginForm) {
            $name = $node->attr('name');
            $value = $node->attr('value');
            if (!in_array($name, [$loginForm['user_field'], $loginForm['pass_field']])) {
                $loginForm['extra_fields'][$name] = $value;
            }
        });
        return $loginForm;
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

