# Subtitle Font PNP

The ultimate anime subtitle font dynamic load solution :)

## Usage

1. Create `config.js` & `config.php` accordingly
1. Put your fonts mess into `build_index/source/` folder
1. Run `index.js` to build the index
1. Run `install.php` to symlink used fonts to `fonts` folder aside to the `*.ass` files
1. Open MPC-HC and enjoy :)

## FAQ

### How it works?

The MPC-HC loads fonts dynamically from `fonts` folder: [reference](https://github.com/clsid2/mpc-hc/blob/22dccf89ac98c3a984909729c8ede4d03acbb503/src/mpc-hc/MainFrm.cpp#L16348-L16374).

### Why PHP + NodeJS?

NodeJS helps us to build the index.

PHP script helps us to actually "install" the fonts (by symlink).

Synology NAS comes with PHP 7.3 out of the box and 8.0 can be installed easily, while NodeJS is limited to v12.
