<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Laws\Law;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console as Console;

class DiscoverCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\JobsManager
     */
    private $jobsManager;

    /**
     * @var \ShvetsGroup\Service\Meta
     */
    private $meta;

    private $reset = false;
    private $reset_meta = false;
    private $re_download = false;

    /**
     * @param \ShvetsGroup\Service\JobsManager $jobsManager
     * @param \ShvetsGroup\Service\Meta $meta
     */
    public function __construct($jobsManager, $meta)
    {
        parent::__construct('discover');

        $this->setDescription('Discover law issuers and their law listings.');
        $this->setHelp('Discover law issuers and their law listings. Usually, you would want to run this at the very beginning and then only run "update" to fetch new laws.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Run law discovery from the beginning of time.');
        $this->addOption('reset_meta', 'm', Console\Input\InputOption::VALUE_NONE, 'Reset the law meta cache (issuers, types, etc.)');
        $this->addOption('download', 'd', Console\Input\InputOption::VALUE_NONE, 'Re-download any page from the live website.');

        $this->jobsManager = $jobsManager;
        $this->meta = $meta;
    }

    /**
     * Execute console command.
     *
     * @param Console\Input\InputInterface   $input
     * @param Console\Output\OutputInterface $output
     *
     * @return bool
     * @throws \Exception
     */
    protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output)
    {
        $this->reset = $input->getOption('reset');
        $this->reset_meta = $input->getOption('reset_meta');
        $this->re_download = $input->getOption('download');

        if ($this->meta->isEmpty() || $this->reset_meta) {
            $this->meta->parse($this->re_download);
            if ($this->reset_meta) {
                die();
            }
        }

        $this->discoverNewLaws($this->reset);

        $this->jobsManager->launch(50, 'discover', 'discover_command', 'discoverDailyLawList');
        $this->jobsManager->launch(50, 'discover', 'discover_command', 'discoverDailyLawListPage');

        return true;
    }

    /**
     * Add discovery jobs for all dates since most recent law.
     *
     * @param bool $reset
     */
    public function discoverNewLaws($reset = false)
    {
        $most_recent = Law::orderBy('date', 'desc')->take(1)->value('date');
        if ($most_recent && !$reset) {
            $this->addLawListJobs($most_recent . ' -1 day', true);
        }
        else {
            $this->addLawListJobs();
        }
    }

    /**
     * Schedule crawls of each law list pages.
     *
     * @param null $starting_date If not passed or null, the 1991-01-01 will be taken as default.
     * @param bool $re_download Whether or not force re-download of the listings. Might be useful when updating recent days.
     */
    protected function addLawListJobs($starting_date = null, $re_download = false)
    {
        $this->jobsManager->deleteAll('discover');

        $date = strtotime($starting_date ?: '1991-01-01 00:00:00');
        $date = max($date, strtotime('1991-01-01 00:00:00'));

        if ($date <= strtotime('1991-01-01 00:00:00')) {
            $this->jobsManager->add('discover_command', 'discoverDailyLawList', [
                'law_list_url' => '/laws/main/ay1990/page',
                'date' => date('Y-m-d', $date),
            ], 'discover');
        }

        while ($date <= strtotime('midnight') && (!max_date() || (max_date() && $date < strtotime(max_date())))) {
            $this->jobsManager->add('discover_command', 'discoverDailyLawList', [
                'law_list_url' => '/laws/main/a' . date('Ymd', $date) . '/sp5/page',
                'date' => date('Y-m-d', $date),
                're_download' => $re_download
            ], 'discover');
            $date = strtotime(date('c', $date) . '+1 day');
        }
    }

    /**
     * Crawl the daily law list page. Take the number of law list pages from it and schedule crawls for each of them.
     *
     * @param string $law_list_url
     * @param string $date
     * @param bool $re_download
     */
    public function discoverDailyLawList($law_list_url, $date, $re_download = false)
    {
        $data = downloadList($law_list_url, [
            're_download' => $re_download || $this->re_download,
            'save' => $date != date('Y-m-d')
        ]);
        for ($i = 1; $i <= $data['page_count']; $i++) {
            $this->jobsManager->add('discover_command', 'discoverDailyLawListPage', [
                'law_list_url' => $law_list_url . ($i > 1 ? $i : ''),
                'date' => $date,
                'page_num' => $i,
                're_download' => $re_download
            ], 'discover');
        }
    }

    /**
     * Crawl the law list page. Take all law urls from it and add them to database.
     *
     * @param string $law_list_url Law list URL.
     * @param string $date
     * @param int $page_num
     * @param bool $re_download
     */
    public function discoverDailyLawListPage($law_list_url, $page_num, $date, $re_download = false)
    {
        $data = downloadList($law_list_url, [
            're_download' => $page_num > 1 ? ($re_download || $this->re_download) : false,
            'save' => $date != date('Y-m-d')
        ]);
        foreach ($data['laws'] as $id => $law) {
            Law::firstOrCreate(['id' => $id])->update(['date' => $law['date']]);
            $this->jobsManager->add('download_command', 'downloadCard', ['id' => $id], 'download', 1);
        }
    }
}