<?php

namespace Kat\TransportProto\message\type;

use Kat\TransportProto\message\PBMessage;

/**
 * @author Nikolai Kordulla
 */
class PBEnum extends PBScalar
{
        var $wired_type = PBMessage::WIRED_VARINT;
        protected $names = [];
        
        /**
         * Parses the message for this type
         *
         * @param array
         */
        public function ParseFromArray()
        {
                $this->value = $this->reader->next();
                
                $this->clean();
        }

        /**
         * Serializes type
         */
        public function SerializeToString($rec=-1)
        {
                $string = '';

                if ($rec > -1)
                {
                        $string .= $this->base128->set_value($rec << 3 | $this->wired_type);
                }

                $value = $this->base128->set_value($this->value);
                $string .= $value;

                return $string;
        }
        
        public function get_description()
        {
                if (isset($this->names[$this->value]))
                        return $this->names[$this->value];
                
                return "";
        }
}
