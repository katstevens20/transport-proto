<?php
namespace Kat\TransportProto\parser;

/**
 * Parse a .proto file and generates the classes in a file
 * @author Nikolai Kordulla
 */
class PBParser
{
    // the message types array of (field, param[]='repeated,required,optional')
    var $m_types = [];
    
    // the IMPORTED message types array of (field, param[]='repeated,required,optional')
    var $m_types_imported = [];
    
    // the message classtype
    var $c_types = [];
       
    // different typess
    var $scalar_types = ['double'	=> 'PB64Bit', 'float'		=> 'PB32Bit', 'int32'		=> 'PBInt', 'int64'		=> 'PBInt', 'uint32'	=> 'PBInt', 'uint64'	=> 'PBInt', 'sint32'	=> 'PBSignedInt', 'sint64'	=> 'PBSignedInt', 'fixed32'	=> 'PB32Bit', 'fixed64'	=> 'PB64Bit', 'sfixed32'	=> 'PB32Bit', 'sfixed64'	=> 'PB64Bit', 'bool'		=> 'PBBool', 'string'	=> 'PBString', 'bytes'		=> 'PBBytes', 'enum'		=> 'PBEnum'];

        // the created filename
    var $created_php_file_name;
    
        // the requires
    var $requires = [];
    
    // parser array
    var $parsers = [];
    
    var $namespace = "";
    
    var $isPHP5_3 = false;

    /**
     * parses the profile and generates a filename with the name
     * pb_proto_[NAME]
     * @param String $protofile - the protofilename with the path
     */
    public function parse($protofile, $outputfile = null)
    {       
        $string = file_get_contents($protofile);

        $this->isPHP5_3 = version_compare(PHP_VERSION, '5.3', '>'); 
        
        // now take the filename
        //$filename = str_replace("\\", "/", $filename);
        $filename = explode("/", $protofile);
        $filename = $filename[count($filename) - 1];
        // strip the comments out of the protofile
        $this->_strip_comments($string);
        $string = trim($string);
        $this->_parse_message_type($string, '');
        $keys = array_keys($this->m_types);        
        unset($this->m_types[$keys[count($keys) -1]]);
        //$this->m_types = $this->m_types[0]['value'];
        // now create file with classes
        $name = explode('.', $filename);
                
        if (count($name) > 1)
        {
                array_pop($name);
        }
        $name = join($name, '.');
               
        if (empty($outputfile))
                $outputfile = 'pb_proto_' . $name . '.php';
                
        $this->created_php_file_name = $outputfile;
        
        $this->_create_class_file($outputfile);
    }

    /**
     * Creates php class file for the proto file
     *
     * @param String $filename - the filename of the php file
     */
    private function _create_class_file($filename)
    {           
        $string = '';                    
        if (!empty($this->namespace))
                $string .= "namespace " . str_replace("\\\\", "\\", $this->namespace) .";\n";
                
        $requires_string = "";
        foreach( $this->requires as $file )
        {
          $requires_string .= sprintf( "require_once( \"%s\" );\n", $file );
        }

        $string .= $requires_string;
                
        foreach ($this->m_types as $classfile)
        {
            $classname = str_replace(".", "_", $classfile['name']);
                                    
            if ($classfile['type'] == 'message')
            {
                if ($this->namespace)
                        $string .= 'class ' . $classname  . " extends \PBMessage\n{\n";
                else    
                        $string .= 'class ' . $classname  . " extends PBMessage\n{\n";
                
                $this->_create_class_constructor($classfile['value'], $string, $classname);
                $this->_create_class_body($classfile['value'], $string, $classname);
                $this->c_types[$classfile['name']] = 'PBMessage';
            }
            else if ($classfile['type'] == 'enum')
            {
                if ($this->namespace)
                        $string .= 'class ' . $classname  . " extends \PBEnum\n{\n";
                else
                        $string .= 'class ' . $classname  . " extends PBEnum\n{\n";
                        
                $this->_create_class_definition($classfile['value'], $string);
                $this->c_types[$classfile['name']] = 'PBEnum';
            }

            // now create the class body with all set and get functions

            $string .= "}\n";
        }
        
        file_put_contents($filename, '<?php' . "\n" . $string . '?>');
    }
        
        /**
         * Gets the type
         * @param field array
         * @return type
         */
        private function _get_type($field)
        {
                if (isset($this->scalar_types[$field['value']['type']]))
                        return $this->scalar_types[$field['value']['type']];
                else if (isset($this->c_types[$field['value']['namespace']]))
                        return $this->c_types[$field['value']['namespace']];
                else if (isset($this->c_types[$field['value']['type']]))                        
                        return $this->c_types[$field['value']['type']];
                        
                // nothing found so search in imports
                foreach ($this->parsers as $parser)
                {
                        $ret = $parser->_get_type($field);
                        if ($ret != false)
                                return $ret;
                }
                        
                return false;                   
        }
        
    /**
     * Creates the class body with functions for each field
     * @param Array $classfile
     * @param String $string
     * @param String $classname - classname
     */
    private function _create_class_body($classfile, &$string, $classname)
    {
        foreach($classfile as $field)
        {
                $type = $this->_get_type($field);
                //var_dump($type);
                //$type = $this->_get_type($field['value']['type']);
                        if ( isset($field['value']['repeated']) && ( isset($this->scalar_types[$field['value']['type']]) 
                                                                                                        || $type == 'PBEnum') )
                        {
                $string .= '  function ' . $field['value']['name'] . '($offset)' . "\n  {\n";
                $string .= '    $v = $this->_get_arr_value("' . $field['value']['value'] . '", $offset);'  . "\n";
                $string .= '    return $v->get_value();' . "\n";;
                $string .= "  }\n";

                $string .= '  function append_' .  $field['value']['name'] . '($value)' . "\n  {\n";
                $string .= '    $v = $this->_add_arr_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= '    $v->set_value($value);' . "\n";;
                $string .= "  }\n";                

                $string .= '  function set_' .  $field['value']['name'] . '($index, $value)' . "\n  {\n";
                $string .= '    $v = new self::$fields["' . $classname. '"]["' . $field['value']['value'] . '"]();' . "\n";
                $string .= '    $v->set_value($value);' . "\n";
                $string .= '    $this->_set_arr_value("' . $field['value']['value'] . '", $index, $v);'  . "\n";
                $string .= "  }\n";

                $string .= '  function remove_last_' .  $field['value']['name'] . '()' . "\n  {\n";
                $string .= '    $this->_remove_last_arr_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";

                $string .= '  function ' . $field['value']['name'] . 's_size()' . "\n  {\n";
                $string .= '    return $this->_get_arr_size("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";
                
                $string .= '  function get_' . $field['value']['name'] . 's()' . "\n  {\n";
                $string .= '    return $this->_get_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";                               
                        }                       
            else if (isset($field['value']['repeated']))
            {
                $string .= '  function ' . $field['value']['name'] . '($offset)' . "\n  {\n";
                $string .= '    return $this->_get_arr_value("' . $field['value']['value'] . '", $offset);'  . "\n";
                $string .= "  }\n";

                $string .= '  function add_' .  $field['value']['name'] . '()' . "\n  {\n";
                $string .= '    return $this->_add_arr_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";

                $string .= '  function set_' .  $field['value']['name'] . '($index, $value)' . "\n  {\n";
                $string .= '    $this->_set_arr_value("' . $field['value']['value'] . '", $index, $value);'  . "\n";
                $string .= "  }\n";
                
                $string .= '  function set_all_' . $field['value']['name'] . 's($values)' . "\n  {\n";
                $string .= '    return $this->_set_arr_values("' . $field['value']['value'] . '", $values);'  . "\n";
                $string .= "  }\n";

                $string .= '  function remove_last_' .  $field['value']['name'] . '()' . "\n  {\n";
                $string .= '    $this->_remove_last_arr_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";

                $string .= '  function ' . $field['value']['name'] . 's_size()' . "\n  {\n";
                $string .= '    return $this->_get_arr_size("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";
                
                $string .= '  function get_' . $field['value']['name'] . 's()' . "\n  {\n";
                $string .= '    return $this->_get_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";                               
            }
            else
            {
                $string .= '  function ' . $field['value']['name'] . "()\n  {\n";
                $string .= '    return $this->_get_value("' . $field['value']['value'] . '");'  . "\n";
                $string .= "  }\n";

                $string .= '  function set_' .  $field['value']['name'] . '($value)' . "\n  {\n";
                $string .= '    return $this->_set_value("' . $field['value']['value'] . '", $value);'  . "\n";
                $string .= "  }\n";                
                
                if ($type == 'PBEnum')
                {
                        $string .= '  function ' . $field['value']['name'] . "_string()\n  {\n";
                        $string .= '    return $this->values["' . $field['value']['value'] . '"]->get_description();'  . "\n";
                        $string .= "  }\n";
                }
            }
        }
    }

    /**
     * Creates the class definitions
     * @param Array $classfile
     * @param String $string
     */
    private function _create_class_definition($classfile, &$string)
    {
        $ctorStr = '  public function __construct($reader=null)' .
                           "\n  {\n   \t" . 'parent::__construct($reader);' ."\n";
                                                
        $ctorStr .= " \t" . '$this->names = array(';
        
        $i = 0;
        foreach($classfile as $field)
        {
            $string .= '  const ' . $field['0'] . '  = ' . $field['1'] . ";\n";
            $ctorStr .= "\n\t\t\t" . $field['1'] . ' => "' . $field['0'] . '"';
            
            if ($i < count($classfile) - 1)
                $ctorStr .= ",";
            else
                $ctorStr .= ");\n";
            $i++;
        }
        
        $ctorStr .= "   }\n";
        
        $string .= "\n" . $ctorStr;                                                                       
    }


    /**
     * Creates the class constructor
     * @param Array $classfile
     * @param String $string
     * @param String $classname - classname
     */
    private function _create_class_constructor($classfile, &$string, $classname)
    {
        if ($this->namespace)               
                $string .= '  var $wired_type = \PBMessage::WIRED_LENGTH_DELIMITED;' . "\n";
        else
                $string .= '  var $wired_type = PBMessage::WIRED_LENGTH_DELIMITED;' . "\n";
                
        $string .= "  public function __construct(" . '$reader=null'  . ")\n  {\n";
        $string .= "    parent::__construct(" . '$reader'  . ");\n";

        foreach($classfile as $field)
        {
            $classtype = "";
            $classtype = $field['value']['type'];
            $classtype = str_replace(".", "_", $classtype);
            $_classtype = $classtype;            
            
            // create the right namespace
            if (isset($this->scalar_types[strtolower($classtype)]))
            {
                $classtype = $this->scalar_types[$classtype];
                
                if (!empty($this->namespace))
                        $classtype = "\\\\$classtype"; 
            }
            else if ((strpos($classtype, '_') === false))
            {
                $classtype = str_replace('.', '_', $field['value']['namespace']);
                
                if (!empty($this->namespace))
                        $classtype = "\\\\$this->namespace\\\\$classtype";
            }
                
               
                
            $fieldName = $field['value']['name'];                       
                        
            $string .= '    self::$fields["' . $classname. '"]["' . $field['value']['value'] . '"] = "' . $classtype . '"' . ";\n";

            if (isset($field['value']['repeated']))
            {
                $string .= '    $this->values["' . $field['value']['value'] . '"] = array()' . ";\n";
            }
            else
            {
                //$string .= '    $this->fields["' . $field['value']['value'] . '"] = new ' . $classtype . "();\n";
                $string .= '    $this->values["' . $field['value']['value'] . '"] = ""' . ";\n";
            }

            // default value only for optional fields
            if (!isset($field['value']['repeated']) && isset($field['value']['optional'])
                    && isset($field['value']['default']))
            {
                if (!empty($this->namespace))
                        $classtype = str_replace("\\\\", "\\", $classtype); // when createing object - it should not be escaped 
                
                $string .= '    $this->values["' . $field['value']['value'] . '"] = new ' . $classtype . "();\n";
                if (isset($this->scalar_types[strtolower($_classtype)]))
                    $string .= '    $this->values["' . $field['value']['value'] . '"]->value = ' . $field['value']['default'] . '' . ";\n";
                // it must be an enum field perhaps type check
                else
                    $string .= '    $this->values["' . $field['value']['value'] . '"]->value = ' . $classtype . '::' . $field['value']['default'] . '' . ";\n";
            }
            
            $string .= '    self::$fieldNames["' . $classname. '"]["' . $field['value']['value'] . '"] = "' . $fieldName . '"' . ";\n";
        }
        $string .= "  }\n";
    }


    /**
     * Parses the message
     * @param String $string the proton file as string
     */
    private function _parse_message_type(&$string, $m_name, $path = '')
    {
        $myarray = [];
        $string = trim($string);
        if ($string == '')
            return;

        //var_dump($m_name);

        while (strlen($string) > 0)
        {
            $next = ($this->_next($string));
            if (strtolower($next) == 'message')
            {
                $string = trim(substr($string, strlen($next)));
                $name = $this->_next($string);

                $offset = $this->_get_begin_end($string, "{", "}");
                // now extract the content and call parse_message again
                $content = trim(substr($string, $offset['begin'] + 1, $offset['end'] - $offset['begin'] - 2));
                $this->_parse_message_type($content, $name, trim($path . '.' . $name, '.'));

                $string = '' . trim(substr($string, $offset['end']));
            }
            else if (strtolower($next) == 'enum')
            {
                $string = trim(substr($string, strlen($next)));
                $name = $this->_next($string);
                $offset = $this->_get_begin_end($string, "{", "}");
                // now extract the content and call parse_message again
                $content = trim(substr($string, $offset['begin'] + 1, $offset['end'] - $offset['begin'] - 2));
                // now adding all to myarray
                $newName = trim($path . '.' . $name, '.');
                $this->m_types[$newName] = ['name' => $newName, 'type' => 'enum', 'value' => $this->_parse_enum($content)];
                // removing it from string
                $string = '' . trim(substr($string, $offset['end']));
            }
            else if (strtolower($next) == 'package')
            {                   
                $string = trim(substr($string, strlen($next)));
                $name = $this->_next($string);
                
                // Use namespace only if php 5.3 or newer
                if ($this->isPHP5_3)
                        $this->namespace = str_replace(".", "\\\\", trim($name, ";"));
                
                //ignoring
                // removing it from string
                $offset = strlen($name);
                $string = '' . trim(substr($string, $offset));
            }
            else if( strtolower($next) == 'import' )
            {
                $name = $this->_next($string);
                $match = preg_match('/"([^"]+)";*\s?/', $string, $matches, PREG_OFFSET_CAPTURE);
                if( !$match )
                  throw new Exception( 'Malformed include / look at your import statement:' . $string );
                        
                $fn = $matches[1][0];
                if( !file_exists($fn) )
                {
                  throw new Exception( "Included file '{$fn}' does not exist" );
                }

                $string = trim(substr($string, $matches[0][1] + strlen($matches[0][0])));

                // parse the imported file
                $pbp = new PBParser();
                $pbp->parse( $fn );
                                
                                // ad the parser to parser array
                                $this->parsers[] = $pbp;
                                
                // add imported message types so PBParser knows about them
                $this->m_types_imported = array_merge( $this->m_types_imported, $pbp->m_types );
                $this->requires[] = $pbp->created_php_file_name;
            }            
            else
            {
                // now a normal field
                $match = preg_match('/(.*);\s?/', $string, $matches, PREG_OFFSET_CAPTURE);
                if (!$match)
                    throw new Exception('Proto file missformed');
                $myarray[] = ['type' => 'field', 'value' => $this->_parse_field($matches[0][0], $myarray, $path)];
                $string = trim(substr($string, $matches[0][1] + strlen($matches[0][0])));
            }
        }

        // now adding myarray to array
        $this->m_types[$path] =  ['name' => $path, 'type' => 'message', 'value' => $myarray];
    }

    /**
     * Parses a normal field
     * @param String $content - content
     */
    private function _parse_field($content, $array, $path)
    {
        $myarray = [];

        // parse the default value
        $match = preg_match('/\[\s?default\s?=\s?([^\[]*)\]\s?;/', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($match)
        {
            $myarray['default'] = $matches[1][0];
            $content = trim(substr($content, 0, $matches[0][1])) . ';';
        }

        // parse the value
        $match = preg_match('/=\s(.*);/', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($match)
        {
            $myarray['value'] = trim($matches[1][0]);
            $content = trim(substr($content, 0, $matches[0][1]));
        }
        else
            throw new Exception('Protofile no value at ' . $content);

        // parse all modifier
        $content = trim(trim(trim($content), ';'));
        $typeset = false;
        while (strlen($content) > 0)
        {
            $matches = $this->_next($content, true);
            $name = $matches[0][0];
            if (strtolower($name) == 'optional')
                $myarray['optional'] = true;
            else if (strtolower($name) == 'required')
                $myarray['required'] = true;
            else if (strtolower($name) == 'repeated')
                $myarray['repeated'] = true;
            else if ($typeset == false)
            {
                $type = $this->_check_type($name, $array, $path);
                $myarray['type'] = $type[0];
                $myarray['namespace'] = $type[1];
                $typeset = true;
            }
            else
            {
                $myarray['name'] = $name;
            }
            $content = trim(substr($content, strlen($name)));
        }

        return $myarray;
    }


    /**
     * Checks if a type exists
     * @param String $type - the type
     */
    private function _check_type($type, $array, $path)
    {
        if (isset($this->scalar_types[strtolower($type)]))
            return [strtolower($type), ''];

        // absolute or relative thing
        // calculate namespace
        $nameprefix = '';
       
        $tempPath = $path;
        while (true) 
        {                       
                $nameprefix = trim("$tempPath.$type", '.');                     
                
                if (isset($this->m_types[$nameprefix]))
                        return [$type, $nameprefix];
                        
                if (empty($tempPath))
                        break;
                                        
                $nameprefix = $tempPath;
                $apath = explode(".", $tempPath);                       
                array_pop($apath);
                $tempPath = join(".", $apath);                                   
        }       
        
        // Now again for imported message types
        // absolute or relative thing
        // calculate namespace
        $nameprefix = '';
       
        $tempPath = $path;
        while (true) 
        {                       
                $nameprefix = trim("$tempPath.$type", '.');                     
                
                if (isset($this->m_types_imported[$nameprefix]))
                        return [$type, $nameprefix];
                        
                if (empty($tempPath))
                        break;
                                        
                $nameprefix = $tempPath;
                $apath = explode("\.", $tempPath);                      
                array_pop($apath);
                $tempPath = join(".", $apath);                                   
        }       
        
             //--------------------- ADDED perhaps easier
        
        // absolute or relative thing
        // calculate namespace
        $namespace = '';
        $namespace = $type;

        $apath = preg_split('#\.#m', $path);
        if ($apath > 1)
        {
            array_pop($apath);
            $namespace = trim(trim(join($apath, '.'), '.') . '.' . $type, '.');
        }

        // try the namespace
        foreach ($this->m_types as $message)
        {
            if ($message['name'] == $namespace)
            {
                return [$type, $namespace];
            }
        }

        // now try one deeper
        $namespace  = trim($path . '.' . $namespace, '.');
        var_dump($namespace);exit();
        foreach ($this->m_types as $message)
        {
            if ($message['name'] == $namespace)
            {
                return [$type, $namespace];
            }
        }
        //--------------------- ADDED END
           
        throw new Exception('Protofile type ' . $type . ' unknown!'); 
    }

    /**
     * Parses enum
     * @param String $content content of the enum
     */
    private function _parse_enum($content)
    {
        $myarray = [];
        $match = preg_match_all('/(.*);\s?/', $content, $matches);
        if (!$match)
            throw new Execption('Semantic error in Enum!');
        foreach ($matches[1] as $match)
        {
            $explode = explode("=", $match);
            $myarray[] = [trim($explode[0]), trim($explode[1])];
        }
        return $myarray;
    }

    /**
     * Gets the next String
     */
    private function _next($string, $reg = false)
    {
        $match = preg_match('/([^\s^\{}]*)/', $string, $matches, PREG_OFFSET_CAPTURE);
        if (!$match)
            return -1;
        if (!$reg)
            return (trim($matches[0][0]));
        else
            return $matches;
    }

    /**
     * Returns the begin and endpos of the char
     * @param String $string protofile as string
     * @param String $char begin element such as '{'
     * @param String $charend end element such as '}'
     * @return array begin, end
     */
    private function _get_begin_end($string, $char, $charend)
    {
        $offset_begin = strpos($string, $char);

        if ($offset_begin === false)
            return ['begin' => -1, 'end' => -1];

        $_offset_number = 1;
        $_offset = $offset_begin + 1;
        while ($_offset_number > 0 && $_offset > 0)
        {
            // now search after the end nested { }
            $offset_open = strpos($string, $char, $_offset);
            $offset_close = strpos($string, $charend, $_offset);
            if ($offset_open < $offset_close && !($offset_open === false))
            {
                $_offset = $offset_open+1;
                $_offset_number++;
            }
            else if (!($offset_close === false))
            {
                $_offset = $offset_close+1;
                $_offset_number--;
            }
            else
                $_offset = -1;
        }

        if ($_offset == -1)
            throw new Exception('Protofile failure: ' . $char . ' not nested');

        return ['begin' => $offset_begin, 'end' => $_offset];
    }

    /**
     * Strips the comments out
     * @param String $string the proton file as string
     */
    private function _strip_comments(&$string)
    {
        $string = preg_replace('/\/\/.+/', '', $string);
        // now replace empty lines and whitespaces in front
        $string = preg_replace('/\\r?\\n\s*/', "\n", $string);
    }
}
