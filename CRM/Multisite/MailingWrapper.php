<?php

/**
 * Wrapper class to restrict Mailing API calls to the current domain.
 */
class CRM_Multisite_MailingWrapper implements API_Wrapper {

  /**
   * Handle API Input
   *
   * @param array $apiRequest
   *
   * @return array
   */
  public function fromApiInput($apiRequest) {
    if (empty($apiRequest['params']['params']['domain_id'])) {
      $apiRequest['params']['params']['domain_id'] = CRM_Core_Config::domainID();
    }

    return $apiRequest;
  }

  /**
   * Handle returning results from API request
   * @param array $apiRequest
   * @param array $result
   *
   * @return array api result
   */
  public function toApiOutput($apiRequest, $result) {
    return $result;
  }

}
