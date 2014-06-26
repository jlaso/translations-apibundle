<?php

/**
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManager;
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

class TranslationsUpdateFromExcelCommand extends ContainerAwareCommand
{

    /** @var  string */
    protected $name;
    /** @var  string */
    protected $description;

    protected function configure()
    {
        $this->name        = 'jlaso:translations:update-from-excel';
        $this->description = 'Update translations from an Excel document';
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addArgument('excel', InputArgument::REQUIRED, 'excel doc')
            ->addArgument('language', InputArgument::REQUIRED, 'language')
        ;
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
                    $regr = "/\({$idx}\)(.*?)\({$idx}\)/";
                    $replc = "%$1%";
                };
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
     *     1   (1)  [1]   => (1) var substitution, [1] style substitution
     */

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        //$project   = $input->getArgument('project');
        $file      = $input->getArgument('excel');
        $language  = $input->getArgument('language');

        $phpExcel  = $container->get('phpexcel');

        /** @var \PHPExcel $excel */
        $excel     = $phpExcel->createPHPExcelObject($file);

        $keySheet = $excel->getSheetByName('key');
        $key = array(); //array_flip(json_decode($keySheet->getCell('A1'), true));
        foreach($keySheet->getRowIterator() as $row){

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

        $worksheet = $excel->getSheetByName($language);

        $output->writeln('<comment>Worksheet - ' . $worksheet->getTitle() . "</comment>");

        foreach ($worksheet->getRowIterator() as $row) {
            /** @var \PHPExcel_Worksheet_Row $row */
            $index = $row->getRowIndex();
            $output->write("<comment>$index</comment>");

            $rowNum       = $row->getRowIndex();

            $keyName   = $this->getCellValue($worksheet, "A{$rowNum}");
            $reference = $this->getCellValue($worksheet, "B{$rowNum}");
            $message   = $this->getCellValue($worksheet, "C{$rowNum}");

            $substituted = $this->substitute($key, $message, $reference);

            $output->write(sprintf("\t<info>%s</info> => %s => <comment>%s</comment>", $keyName, $reference, $substituted));
            echo "\n";
        }

        $output->writeln(" done!");
    }



}