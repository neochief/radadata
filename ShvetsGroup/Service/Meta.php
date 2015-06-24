<?php

namespace ShvetsGroup\Service;

class Meta
{

    /**
     * Whether or not issuers list is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->getIssuers());
    }

    /**
     * Get associate array of all issuers.
     *
     * @return array['name']
     *              ['issuer_id']  string
     *              ['name']       string
     *              ['full_name']  string
     *              ['group_name'] string
     *              ['website']    string
     *              ['url']        string
     */
    public function getIssuers()
    {
        $issuers = [];
        $db_issuers = db('db')->query('SELECT * FROM issuers')->fetchAll();
        foreach ($db_issuers as $issuer) {
            $issuers[$issuer['name']] = $issuer;
        }

        return $issuers;
    }

    /**
     * Assign new list of law states.
     *
     * @param array $issuers
     */
    public function setIssuers(array $issuers)
    {
        foreach ($issuers as $issuer) {
            $sql = "INSERT IGNORE INTO issuers (issuer_id, name, full_name, group_name, website, url) VALUES (:issuer_id, :name, :full_name, :group_name, :website, :url)";
            $q = db('db')->prepare($sql);
            $q->execute([':issuer_id'  => $issuer['issuer_id'],
                         ':name'       => $issuer['name'],
                         ':full_name'  => $issuer['full_name'],
                         ':group_name' => $issuer['group_name'],
                         ':website'    => $issuer['website'],
                         ':url'        => $issuer['url']
            ]);
        }
    }

    /**
     * Assign new list of law types.
     *
     * @param array $types
     */
    public function setTypes(array $types)
    {
        foreach ($types as $type) {
            $sql = "INSERT IGNORE INTO types (type_id, name) VALUES (:type_id, :name)";
            $q = db('db')->prepare($sql);
            $q->execute([':type_id'  => $type['type_id'], ':name' => $type['name']]);
        }
    }

    /**
     * Assign new list of law states.
     *
     * @param array $states
     */
    public function setStates(array $states)
    {
        foreach ($states as $state) {
            $sql = "INSERT IGNORE INTO states (state_id, name) VALUES (:state_id, :name)";
            $q = db('db')->prepare($sql);
            $q->execute([':state_id'  => $state['state_id'], ':name' => $state['name']]);
        }
    }

    /**
     * Parse the issuers, states and law types lists from their listing ( http://zakon.rada.gov.ua/laws/stru/a ).
     *
     * @param bool $re_download
     */
    public function parse($re_download)
    {
        $html = download('/laws/stru/a', $re_download);
        $list = crawler($html);

        for ($i = 1; $i <= 2; $i++) {
            $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . $i . ']/tbody/tr/td/table/tbody/tr';
            $group = null;
            $issuers = [];
            $list->filterXPath($XPATH)->each(
                function ($node) use (&$issuers, &$group, $i) {
                    $cells = $node->filterXPath('//td');
                    if ($cells->count() == 1) {
                        $text = better_trim($cells->text());
                        if ($text) {
                            $group = $text;
                        }
                    } elseif ($cells->count() == 4) {
                        $issuer_link = $node->filterXPath('//td[2]/a');
                        $issuer = [];
                        $issuer['url'] = $issuer_link->attr('href');
                        $issuer['issuer_id'] = str_replace('/laws/main/', '', $issuer['url']);
                        $issuer['group_name'] = $group;
                        $issuer['name'] = better_trim($issuer_link->text());
                        $issuer['full_name'] = null;
                        if (preg_match('|(.*?)(:? \((.*?)\))?$|', $issuer['name'], $match)) {
                            if (isset($match[2])) {
                                $issuer['name'] = $match[2];
                                $issuer['full_name'] = $match[1];
                            }
                        }
                        $issuer['website'] = $issuer_link->count() == 2 ? $issuer_link->last()->attr('href') : null;
                        $issuer['international'] = $i;
                        $issuers[$issuer['name']] = $issuer;
                    }
                }
            );
        }
        $this->setIssuers($issuers);

        $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . 3 . ']/tbody/tr/td/table/tbody/tr';
        $types = [];
        $list->filterXPath($XPATH)->each(
            function ($node) use (&$types) {
                $cells = $node->filterXPath('//td');
                if ($cells->count() == 4) {
                    $type_link = $node->filterXPath('//td[2]/a');
                    $type = [];
                    $type['url'] = $type_link->attr('href');
                    $type['type_id'] = str_replace('/laws/main/', '', $type['url']);
                    $type['name'] = better_trim($type_link->text());
                    $types[$type['name']] = $type;
                }
            }
        );
        $this->setTypes($types);

        $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . 5 . ']/tbody/tr/td/table/tbody/tr';
        $states = [];
        $list->filterXPath($XPATH)->each(
            function ($node) use (&$states) {
                $cells = $node->filterXPath('//td');
                if ($cells->count() == 4) {
                    $state_link = $node->filterXPath('//td[2]/a');
                    $state = [];
                    $state['url'] = $state_link->attr('href');
                    $state['state_id'] = str_replace('/laws/main/', '', $state['url']);
                    $state['name'] = better_trim($state_link->text());
                    $states[$state['name']] = $state;
                }
            }
        );
        $this->setStates($states);

    }
}