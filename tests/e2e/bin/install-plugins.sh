#!/bin/bash

# Clone latest version of a plugin (fallback to default branch if latest version is not found)
install_plugin_from_repo() {
  local PLUGIN_SLUG=$1
  local DIR=$2

  rm -rf "${DIR}"
  git clone --quiet --depth=1 "git@github.com:iamdharmesh/${PLUGIN_SLUG}.git" "${DIR}"

  cd ${DIR}
  npm install && npm run build
  mv ${PLUGIN_SLUG}.zip ../${PLUGIN_SLUG}.zip
  cd -

  rm -rf ${DIR}
  unzip -o ./test-plugins/${PLUGIN_SLUG}.zip -d ./test-plugins
  rm ./test-plugins/${PLUGIN_SLUG}.zip
}

# Install Subscriptions.
install_plugin_from_repo "woocommerce-subscriptions" "./test-plugins/woocommerce-subscriptions"

# Install Pre-Orders
install_plugin_from_repo "woocommerce-pre-orders" "./test-plugins/woocommerce-pre-orders"
