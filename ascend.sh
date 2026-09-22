#!/bin/bash

echo "AscensionPlatform Scaffolding";
echo "------------------------------";
echo " Available Flags:"
echo "    -h    This menu that provides information about what commands can be used with the builder."
echo "    -n    Create a new route and classes by passing a name of route  e.g. ./ascend.sh -n CardPayment."
echo "    -d    Delete a scaffolded route and all its artifacts e.g. ./ascend.sh -d CardPayment."

while getopts n:d:h flag
do
    case "${flag}" in
        n)
            classname=${OPTARG}
            echo "Scaffolding functionality: $classname"
            echo ""
            echo "----------"
            echo " You can now access scaffolded area via: /$classname"
            echo " ";
            echo " Creating new class structure "

            if [ ! -d "lib/$classname" ]
            then {
                mkdir -p "lib/$classname/Controller"
                mkdir -p "lib/$classname/Interfaces"
                mkdir -p "lib/$classname/Repository"

                cp "builder/templates/Example/Controller/Controller.php" "lib/$classname/Controller/Controller.php"
                sed -i "s/{Example}/$classname/g" "lib/$classname/Controller/Controller.php"

                cp "builder/templates/Example/Interfaces/IRepository.php" "lib/$classname/Interfaces/IRepository.php"
                sed -i "s/{Example}/$classname/g" "lib/$classname/Interfaces/IRepository.php"

                cp "builder/templates/Example/Repository/Repository.php" "lib/$classname/Repository/Repository.php"
                sed -i "s/{Example}/$classname/g" "lib/$classname/Repository/Repository.php"

                mkdir -p "templates/$classname"
                cp "builder/templates/ExampleTwig/Example.twig" "templates/$classname/default.twig"
                sed -i "s/{Example}/$classname/g" "templates/$classname/default.twig"
            }
            else
                echo "lib/$classname already exists - nothing created."
            fi;;

        d)
            classname=${OPTARG}
            # Only remove directories that look like they came from this
            # scaffolder, so `-d` can't be used to blow away an arbitrary
            # hand-written lib/ area. (Previously this flag was accepted but
            # silently did nothing at all.)
            if [ -d "lib/$classname/Controller" ] && [ -d "lib/$classname/Repository" ] && [ -d "lib/$classname/Interfaces" ]
            then
                rm -rf "lib/$classname"
                rm -rf "templates/$classname"
                echo "Removed lib/$classname and templates/$classname"
            else
                echo "lib/$classname does not look like a scaffolded area (missing Controller/Repository/Interfaces) - not removing."
                exit 1
            fi;;

        h)
            echo ""
            echo "Help"
            echo ""
            echo " Available Flags:"
            echo "    -h    This menu that provides information about what commands can be used with the builder."
            echo "    -n    Create a new route and classes by passing a name of route  e.g. ./ascend.sh -n CardPayment."
            echo "    -d    Delete a scaffolded route and all its artifacts e.g. ./ascend.sh -d CardPayment."
            echo ""
            echo " End of menu ";;
    esac
done
