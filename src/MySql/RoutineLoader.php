<?php
//----------------------------------------------------------------------------------------------------------------------
/**
 * phpStratum
 *
 * @copyright 2005-2015 Paul Water / Set Based IT Consultancy (https://www.setbased.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link
 */
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Stratum\MySql;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SetBased\Stratum\Exception\RuntimeException;
use SetBased\Stratum\MySql\StaticDataLayer as DataLayer;
use SetBased\Stratum\NameMangler\NameMangler;
use SetBased\Stratum\Util;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for loading stored routines into a MySQL instance from pseudo SQL files.
 */
class RoutineLoader
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The default character set under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCharacterSet;

  /**
   * The default collate under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCollate;

  /**
   * Object for connection to a database instance.
   *
   * @var Connector.
   */
  private $myConnector;

  /**
   * Name of the class that contains all constants.
   *
   * @var string
   */
  private $myConstantClassName;

  /**
   * An array with source filenames that are not loaded into MySQL.
   *
   * @var array
   */
  private $myErrorFileNames = [];

  /**
   * Class name for mangling routine and parameter names.
   *
   * @var string
   */
  private $myNameMangler;

  /**
   * The metadata of all stored routines. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private $myPhpStratumMetadata;

  /**
   * The filename of the file with the metadata of all stored routines.
   *
   * @var string
   */
  private $myPhpStratumMetadataFilename;

  /**
   * Old metadata of all stored routines. Note: this data comes from information_schema.ROUTINES.
   *
   * @var array
   */
  private $myRdbmsOldMetadata;

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $myReplacePairs = [];

  /**
   * Path where source files can be found.
   *
   * @var string
   */
  private $mySourceDirectory;

  /**
   * The extension of the source files.
   *
   * @var string
   */
  private $mySourceFileExtension;

  /**
   * All sources with stored routines. Each element is an array with the following keys:
   * <ul>
   * <li> path_name    The path the source file.
   * <li> routine_name The name of the routine (equals the basename of the path).
   * <li> method_name  The name of the method in the data layer for the wrapper method of the stored routine.
   * </ul>
   *
   * @var array[]
   */
  private $mySources = [];

  /**
   * The SQL mode under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $mySqlMode;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads stored routines into the current schema.
   *
   * @param string   $theConfigFilename The name of the configuration file of the current project
   * @param string[] $theFileNames      The source filenames that must be loaded. If empty all sources (if required)
   *                                    will loaded.
   *
   * @return int Returns 0 on success, 1 if one or more errors occurred.
   */
  public function main($theConfigFilename, $theFileNames)
  {
    $this->myConnector = new Connector();

    if (empty($theFileNames))
    {
      $this->loadAll($theConfigFilename);
    }
    else
    {
      $this->loadList($theConfigFilename, $theFileNames);
    }

    $this->logOverviewErrors();

    return ($this->myErrorFileNames) ? 1 : 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads parameters from the configuration file.
   *
   * @param string $theConfigFilename
   */
  protected function readConfigFile($theConfigFilename)
  {
    $this->myConnector->readConfigFile($theConfigFilename);

    $settings = parse_ini_file($theConfigFilename, true);

    $this->myPhpStratumMetadataFilename = Util::getSetting($settings, true, 'wrapper', 'metadata');
    $this->myNameMangler                = Util::getSetting($settings, true, 'wrapper', 'mangler_class');
    $this->mySourceDirectory            = Util::getSetting($settings, true, 'loader', 'source_directory');
    $this->mySourceFileExtension        = Util::getSetting($settings, true, 'loader', 'extension');
    $this->myConstantClassName          = Util::getSetting($settings, false, 'loader', 'constant_class');
    $this->mySqlMode                    = Util::getSetting($settings, true, 'loader', 'sql_mode');
    $this->myCharacterSet               = Util::getSetting($settings, true, 'loader', 'character_set');
    $this->myCollate                    = Util::getSetting($settings, true, 'loader', 'collate');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Detects stored routines that would result in duplicate wrapper method name.
   */
  private function detectNameConflicts()
  {
    // Get same method names from array
    list($sources_by_path, $sources_by_method) = $this->getDuplicates();

    // Add every not unique method name to myErrorFileNames
    foreach ($sources_by_path as $source)
    {
      $this->myErrorFileNames[] = $source['path_name'];
    }

    // Log the sources files with duplicate method names.
    foreach ($sources_by_method as $method => $sources)
    {
      echo "The following source files would result wrapper methods with equal name '$method'\n";
      foreach ($sources as $source)
      {
        echo '  '.$source['path_name'], "\n";
      }
    }

    // Remove duplicates from mySources.
    foreach ($this->mySources as $i => $source)
    {
      if (isset($sources_by_path[$source['path_name']]))
      {
        unset($this->mySources[$i]);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops obsolete stored routines (i.e. stored routines that exits in the current schema but for which we don't have
   * a source file).
   */
  private function dropObsoleteRoutines()
  {
    // Make a lookup table from routine name to source.
    $lookup = [];
    foreach ($this->mySources as $source)
    {
      $lookup[$source['routine_name']] = $source;
    }

    // Drop all routines not longer in sources.
    foreach ($this->myRdbmsOldMetadata as $old_routine)
    {
      if (!isset($lookup[$old_routine['routine_name']]))
      {
        echo sprintf("Dropping %s %s\n",
                     strtolower($old_routine['routine_type']),
                     $old_routine['routine_name']);

        $sql = sprintf("drop %s if exists %s", $old_routine['routine_type'], $old_routine['routine_name']);
        DataLayer::executeNone($sql);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches recursively for all source files in a directory.
   *
   * @param string $theSourceDir The directory.
   */
  private function findSourceFiles($theSourceDir = null)
  {
    if ($theSourceDir===null) $theSourceDir = $this->mySourceDirectory;

    /** @var NameMangler $mangler */
    $mangler   = $this->myNameMangler;
    $directory = new RecursiveDirectoryIterator($theSourceDir);
    $directory->setFlags(RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
    $files = new RecursiveIteratorIterator($directory);
    foreach ($files as $full_path => $file)
    {
      // If the file is a source file with stored routine add it to my sources.
      if ($file->isFile() && '.'.$file->getExtension()==$this->mySourceFileExtension)
      {
        $this->mySources[] = ['path_name'    => $full_path,
                              'routine_name' => $file->getBasename($this->mySourceFileExtension),
                              'method_name'  => $mangler::getMethodName($file->getFilename())];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Finds all source files that actually exists from a list of file names.
   *
   * @param array $theFileNames The list of file names.
   */
  private function findSourceFilesFromList($theFileNames)
  {
    /** @var NameMangler $mangler */
    $mangler = $this->myNameMangler;
    foreach ($theFileNames as $psql_filename)
    {
      if (!file_exists($psql_filename))
      {
        echo sprintf("File not exists: '%s'.\n", $psql_filename);
        $this->myErrorFileNames[] = $psql_filename;
      }
      $extension = '.'.pathinfo($psql_filename, PATHINFO_EXTENSION);
      if ($extension==$this->mySourceFileExtension)
      {
        $this->mySources[] = ['path_name'    => $psql_filename,
                              'routine_name' => pathinfo($psql_filename, PATHINFO_FILENAME),
                              'method_name'  => $mangler::getMethodName(pathinfo($psql_filename, PATHINFO_FILENAME))];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Selects schema, table, column names and the column type from MySQL and saves them as replace pairs.
   */
  private function getColumnTypes()
  {
    $query = '
select table_name                                    table_name
,      column_name                                   column_name
,      column_type                                   column_type
,      character_set_name                            character_set_name
,      null                                          table_schema
from   information_schema.COLUMNS
where  table_schema = database()
union all
select table_name                                    table_name
,      column_name                                   column_name
,      column_type                                   column_type
,      character_set_name                            character_set_name
,      table_schema                                  table_schema
from   information_schema.COLUMNS
order by table_schema
,        table_name
,        column_name';

    $rows = DataLayer::executeRows($query);
    foreach ($rows as $row)
    {
      $key = '@';
      if (isset($row['table_schema'])) $key .= $row['table_schema'].'.';
      $key .= $row['table_name'].'.'.$row['column_name'].'%type@';
      $key = strtoupper($key);

      $value = $row['column_type'];
      if (isset($row['character_set_name'])) $value .= ' character set '.$row['character_set_name'];

      $this->myReplacePairs[$key] = $value;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads constants set the PHP configuration file and  adds them to the replace pairs.
   */
  private function getConstants()
  {
    // If myTargetConfigFilename is not set return immediately.
    if (!isset($this->myConstantClassName)) return;

    $reflection = new \ReflectionClass($this->myConstantClassName);

    foreach ($reflection->getConstants() as $name => $value)
    {
      if (!is_numeric($value)) $value = "'$value'";

      $this->myReplacePairs['@'.$name.'@'] = $value;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the SQL mode in the order as preferred by MySQL.
   */
  private function getCorrectSqlMode()
  {
    $sql = sprintf("set sql_mode ='%s'", $this->mySqlMode);
    DataLayer::executeNone($sql);

    $query           = "select @@sql_mode;";
    $tmp             = DataLayer::executeRows($query);
    $this->mySqlMode = $tmp[0]['@@sql_mode'];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns all elements in {@link $mySources} with duplicate method names.
   *
   * @return array[]
   */
  private function getDuplicates()
  {
    // First pass make lookup table by method_name.
    $lookup = [];
    foreach ($this->mySources as $source)
    {
      if (!isset($lookup[$source['method_name']]))
      {
        $lookup[$source['method_name']] = [];
      }

      $lookup[$source['method_name']][] = $source;
    }

    // Second pass find duplicate sources.
    $duplicates_sources = [];
    $duplicates_methods = [];
    foreach ($this->mySources as $source)
    {
      if (sizeof($lookup[$source['method_name']])>1)
      {
        $duplicates_sources[$source['path_name']]   = $source;
        $duplicates_methods[$source['method_name']] = $lookup[$source['method_name']];
      }
    }

    return [$duplicates_sources, $duplicates_methods];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about all stored routines in the current schema.
   */
  private function getOldStoredRoutinesInfo()
  {
    $query = "
select routine_name
,      routine_type
,      sql_mode
,      character_set_client
,      collation_connection
from  information_schema.ROUTINES
where ROUTINE_SCHEMA = database()
order by routine_name";

    $rows = DataLayer::executeRows($query);

    $this->myRdbmsOldMetadata = [];
    foreach ($rows as $row)
    {
      $this->myRdbmsOldMetadata[$row['routine_name']] = $row;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines into MySQL.
   *
   * @param string $theConfigFilename The filename of the configuration file.
   */
  private function loadAll($theConfigFilename)
  {
    $this->readConfigFile($theConfigFilename);

    $this->myConnector->connect();

    $this->findSourceFiles();
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Drop obsolete stored routines.
    $this->dropObsoleteRoutines();

    // Remove metadata of stored routines that have been removed.
    $this->removeObsoleteMetadata();

    // Write the metadata to file.
    $this->writeStoredRoutineMetadata();

    $this->myConnector->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines in a list into MySQL.
   *
   * @param string $theConfigFilename The filename of the configuration file.
   * @param array  $theFileNames      The list of files to be loaded.
   */
  private function loadList($theConfigFilename, $theFileNames)
  {
    $this->readConfigFile($theConfigFilename);

    $this->myConnector->connect();

    $this->findSourceFilesFromList($theFileNames);
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Write the metadata to @c $myPhpStratumMetadataFilename.
    $this->writeStoredRoutineMetadata();

    $this->myConnector->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines.
   */
  private function loadStoredRoutines()
  {
    // Sort the sources by routine name.
    usort($this->mySources, function ($a, $b)
    {
      return strcmp($a['routine_name'], $b['routine_name']);
    });

    // Process all sources.
    foreach ($this->mySources as $filename)
    {
      $routine_name = $filename['routine_name'];

      $helper = new RoutineLoaderHelper($filename['path_name'],
                                        $this->mySourceFileExtension,
                                        isset($this->myPhpStratumMetadata[$routine_name]) ? $this->myPhpStratumMetadata[$routine_name] : null,
                                        $this->myReplacePairs,
                                        isset($this->myRdbmsOldMetadata[$routine_name]) ? $this->myRdbmsOldMetadata[$routine_name] : null,
                                        $this->mySqlMode,
                                        $this->myCharacterSet,
                                        $this->myCollate);

      $meta_data = $helper->loadStoredRoutine();
      if ($meta_data===false)
      {
        # An error occurred during the loading og the stored routine.
        $this->myErrorFileNames[] = $filename['path_name'];
        unset($this->myPhpStratumMetadata[$routine_name]);
      }
      else
      {
        # Stored routine is successfully loaded.
        $this->myPhpStratumMetadata[$routine_name] = $meta_data;
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the source files that were not successfully loaded into MySQL.
   */
  private function logOverviewErrors()
  {
    foreach ($this->myErrorFileNames as $filename)
    {
      echo sprintf("Error loading file '%s'.\n", $filename);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the metadata of stored routines from the metadata file.
   */
  private function readStoredRoutineMetadata()
  {
    if (file_exists($this->myPhpStratumMetadataFilename))
    {
      $this->myPhpStratumMetadata = json_decode(file_get_contents($this->myPhpStratumMetadataFilename), true);
      if (json_last_error()!=JSON_ERROR_NONE)
      {
        throw new RuntimeException("Error decoding JSON: '%s'.", json_last_error_msg());
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes obsolete entries from the metadata of all stored routines.
   */
  private function removeObsoleteMetadata()
  {
    // 1 pass through $mySources make new array with routine_name is key.
    $clean = [];
    foreach ($this->mySources as $source)
    {
      $routine_name = $source['routine_name'];
      if (isset($this->myPhpStratumMetadata[$routine_name]))
      {
        $clean[$routine_name] = $this->myPhpStratumMetadata[$routine_name];
      }
    }

    $this->myPhpStratumMetadata = $clean;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Writes the metadata of all stored routines to the metadata file.
   */
  private function writeStoredRoutineMetadata()
  {
    $json_data = json_encode($this->myPhpStratumMetadata, JSON_PRETTY_PRINT);
    if (json_last_error()!=JSON_ERROR_NONE)
    {
      throw new RuntimeException("Error of encoding to JSON: '%s'.", json_last_error_msg());
    }

    // Save the metadata.
    Util::writeTwoPhases($this->myPhpStratumMetadataFilename, $json_data);
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
