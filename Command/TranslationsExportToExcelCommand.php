<?php

/**
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Service\ClientSocketService;
use JLaso\TranslationsBundle\Document\Repository\TranslationRepository;
use JLaso\TranslationsBundle\Document\Translation;
use JLaso\TranslationsBundle\Entity\Project;
use JLaso\TranslationsBundle\Entity\Repository\ProjectRepository;
use JLaso\TranslationsBundle\Entity\User;
use JLaso\TranslationsBundle\Service\MailerService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;

class TranslationsExportToExcelCommand extends ContainerAwareCommand
{

    /** @var  string */
    protected $name;
    /** @var  string */
    protected $description;
    /** @var ClientSocketService */
    private $clientApiService;

    protected function configure()
    {
        $this->name        = 'jlaso:translations:export-to-excel';
        $this->description = 'Export translations to an Excel document';
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addArgument('excel', InputArgument::REQUIRED, 'excel doc')
            ->addArgument('language', InputArgument::REQUIRED, 'language')
            ->addOption('port', null, InputArgument::OPTIONAL, 'port')
            ->addOption('address', null, InputArgument::OPTIONAL, 'address')
            ->addOption('approved', null, InputArgument::OPTIONAL, 'approved')
        ;
    }

    protected function init($server = null, $port = null)
    {
        /** @var ClientSocketService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
        $this->clientApiService->init($server, $port);
    }

    /**
     * @param $keys
     * @param $needle
     * @param $reference
     *
     * @return mixed
     */
    protected function substitute($keys, $needle, $reference)
    {

        foreach($keys as $srch=>$replc){

            //$srch = str_replace(array("(",")","[","]"), array('\(','\)','\[','\]'));
            if(preg_match("/\((?<idx>\d+)\)/", $srch, $match)){
                $idx = $match['idx'];
                $regr = "/\({$idx}\)(?<val>.*?)\({$idx}\)/";
                if(preg_match($regr, $reference, $match)){
                    $replc = "%".$match['val']."%";
                }else{
                    //$regr = "/\({$idx}\)(.*?)\({$idx}\)/";
                    $replc = "%$1%";
                };
                $regr = "/\(\s?{$idx}\s?\)(.*?)\(\s?{$idx}\s?\)/";
            }else{
                if(preg_match("/\[(?<idx>\d+)\]/", $srch, $match)){
                    $idx = $match['idx'];
                    $regr = "/\[\s?{$idx}\s?\]/";  //print "\n\t$idx\t$regr\t$replc\n";
                }else{
                    die("error in substitute $srch=>$replc");
                }
            }
            $needle = preg_replace($regr, $replc, $needle);
        }

        return $needle;
    }

    protected function getCellValue(\PHPExcel_Worksheet $sheet, $coord)
    {
        $cell = $sheet->getCell($coord);
        if($cell){
            return $cell->getValue();
        }
    }

    /**
     * FORMAT for the excel document
     * =============================
     *
     * one worksheet named as the language you want to import
     * one workseeht named "key" with the following format
     *   rowX colA ColB
     *     1   [1]  (1)   => (1) var substitution, [1] style substitution
     *
     * the reason for this "key system" is that normally translators haven't to translate the html labels and variables and this is a way to assure this
     */

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $file      = $input->getArgument('excel');
        $language  = $input->getArgument('language');
        $approved  = (boolean)$input->getOption('approved');

        //$this->init($input->getOption('address'), $input->getOption('port'));

        $phpExcel  = $container->get('phpexcel');

        /** @var \PHPExcel $excel */
        $excel = $phpExcel->createPHPExcelObject();
        $excel->getProperties()->setCreator("Maarten Balliauw")
            ->setLastModifiedBy("Maarten Balliauw")
            ->setTitle("Office 2007 XLSX Test Document")
            ->setSubject("Office 2007 XLSX Test Document")
            ->setDescription("Test document for Office 2007 XLSX, generated using PHP classes.")
            ->setKeywords("office 2007 openxml php")
            ->setCategory("Test result file");

        $newSheet = clone $excel->getActiveSheet();

        $excel->setActiveSheetIndex(0)
            ->setTitle($language)
            ->setCellValue('A1', "key (don't translate)")
            ->setCellValue('B1', $language . " (don't translate)")
            ->setCellValue('C1', 'New language (here the translation)');

        $excel->addSheet($newSheet);

        $excel->setActiveSheetIndex(1)
            ->setTitle('keys')
            ->setCellValue('A4', 'Miscellaneous glyphs')
            ->setCellValue('A5', 'éàèùâêîôûëïüÿäöüç');

        $excel->getActiveSheet()->setCellValue('A8',"Hello\nWorld");
        $excel->getActiveSheet()->getRowDimension(8)->setRowHeight(-1);
        $excel->getActiveSheet()->getStyle('A8')->getAlignment()->setWrapText(true);

        $objWriter = new \PHPExcel_Writer_Excel5($excel);
        $objWriter->save($file);

        die('OK');

        $keySheet = $excel->getSheetByName('key');
        $key = array(); //array_flip(json_decode($keySheet->getCell('A1'), true));
        foreach($keySheet->getRowIterator() as $row){
var_dump($row->getRowIndex());
            $rowNum = $row->getRowIndex();
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set

            foreach ($cellIterator as $cell) {
                /** @var \PHPExcel_Cell $cell */
                $cellValue = $cell->getCalculatedValue();
                switch($cell->getColumn()){
                    case("A"):
                        $index = "[$rowNum]";
                        break;
                    case("B"):
                        $index = "($rowNum)";
                        break;
                };
                if (!is_null($cellValue)) {
                    $key[$index] = $cellValue;
                }
            }
        }

        // get the worksheet that match its title with language
        $worksheet = $excel->getSheetByName($language);

        $output->writeln("\n<comment>Worksheet - " . $worksheet->getTitle() . "</comment>");
        $localData = array();

        foreach ($worksheet->getRowIterator() as $row) {
            /** @var \PHPExcel_Worksheet_Row $row */
            $index       = $row->getRowIndex();
            $rowNum      = $row->getRowIndex();
            $keyName     = $this->getCellValue($worksheet, "A{$rowNum}");
            $reference   = $this->getCellValue($worksheet, "B{$rowNum}");
            $message     = $this->getCellValue($worksheet, "C{$rowNum}");
            $substituted = $this->substitute($key, $message, $reference);
            //$output->writeln(sprintf("<comment>$index</comment>\t<info>%s</info> => %s => <comment>%s</comment>", $keyName, $reference, $substituted));
            $localData[$keyName] = $substituted;
        }

        // download translations from server
        $result = $this->clientApiService->getCatalogIndex();

        if($result['result']){
            $catalogs = $result['catalogs'];
        }else{
            die('error getting catalogs');
        }

        $tempData = array();

        foreach($catalogs as $catalog){

            $output->writeln(PHP_EOL . sprintf('<info>Processing "%s" catalog ...</info>', $catalog));

            $result = $this->clientApiService->downloadKeys($catalog);
            //var_dump($result); die;
            file_put_contents('/tmp/' . $catalog . '.json', json_encode($result));
            $bundles = $result['bundles'];
            //var_dump($result['data']['Bad credentials']); die;

            foreach($result['data'] as $key=>$data){

                foreach($data as $locale=>$messageData){

                    if(($locale == $language) && isset($localData[$key])){
                        $tempData[$key][$catalog] = array_merge($messageData, array('new' => $localData[$key]));
                        //$output->writeln(sprintf("\t|-- key %s:%s/%s ... ", $catalog, $key, $locale));
                        echo '.';
                        //$fileName = isset($messageData['fileName']) ? $messageData['fileName'] : '';
                    }

                }
            }
        }

        //print_r($tempData);

        $output->writeln("\nAnalysing the result of the match process...\n");
        $count = 0;
        // data to send to translations server
        $data = array();
        // this date guarantees that the data sent to server forces to update key
        $date = date('c');

        // get the key that are repeated
        foreach($tempData as $key=>$restData){
            if(count($restData) > 1){
                $output->writeln("\tthe key $key is in more that one catalog");
            }
            foreach($restData as $catalog=>$messageData){

                if(!empty($messageData['new']) && ($messageData['message'] != $messageData['new'])){

                    //var_dump($messageData); die;
                    $data[$key][$language] = array(
                        'approved'  => $approved,
                        'message'   => $messageData['new'],
                        'updatedAt' => $date,
                        'fileName'  => isset($messageData['fileName']) ? $messageData['fileName'] : "",
                        'bundle'    => isset($bundles[$key]) ? $bundles[$key] : "",
                    );
                    $output->writeln("the key $key needs to be updated");
                    $count++;

                }

            }
        }

        $total = count($localData);
        $output->writeln("\nfound $count keys that need to be updated from a total of $total keys that have the file to process\n");

        if($count){
            //ld($data);
            $output->writeln('uploadKeys("' . $catalog . '", $data)');
            $result = $this->clientApiService->uploadKeys($catalog, $data);
            //var_dump($result);
        }

        $output->writeln("\n done!");
    }



}