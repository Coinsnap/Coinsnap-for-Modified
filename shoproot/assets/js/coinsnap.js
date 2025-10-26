/**
 * Copyright since 2023 Coinsnap
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Coinsnap <dev@coinsnap.io>
 * @copyright Since 2023 Coinsnap
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
jQuery(document).ready(function ($) {
    
    if($('#coinsnap_provider').length){
        
        setProvider();
        $('#coinsnap_provider').change(function(){
            setProvider();
        });
    }
    
    if($('#coinsnap_discount_enabled').length){
        
        enableDiscount();
        
        $('#coinsnap_discount_enabled').change(function(){
            enableDiscount();
        });
        
        $('#coinsnap_discount_amount_limit').change(function(){
            if(parseFloat($(this).val()) < 0){
                $(this).val(0);
            }
            if(parseFloat($(this).val()) > 100){
                $(this).val(100);
            }
        });
        
        $('#coinsnap_discount_percentage').change(function(){
            if(parseFloat($(this).val()) < 0){
                $(this).val(0);
            }
            if(parseFloat($(this).val()) > 100){
                $(this).val(100);
            }
        });
        
        $('.discount input').keyup(function() {
            $(this).val($(this).val().replace(/[^0-9,]/g,''));
        });
        
        if($('#coinsnap_discount_enabled').prop('checked')){
            setDiscount();
        }
        
        $('#coinsnap_discount_type').change(function(){
            setDiscount();
        });
    }
    
    function setProvider(){
        if($('#coinsnap_provider').val() !== 'btcpay'){
            $('.form-group.btcpay').hide();
            $('.form-group.btcpay input[type=text]').removeAttr('required');
            $('.form-group.coinsnap').show();
            $('.form-group.coinsnap input[type=text]').attr('required','required');
        }
        else {
            $('.form-group.coinsnap').hide();
            $('.form-group.coinsnap input[type=text]').removeAttr('required');
            $('.form-group.btcpay').show();
            $('.form-group.btcpay input[type=text]').attr('required','required');
        }
    }
    
    function enableDiscount(){
        if($('#coinsnap_discount_enabled').prop('checked')){
            $('.discount').show();
            setDiscount();
        }
        else {
            $('.discount').hide();
        }
    }
    
    function setDiscount(){
        if($('#coinsnap_discount_type').val() === 'fixed'){
            $('.discount.discount-percentage').hide();
            $('.discount.discount-amount').show();
        }
        else {
            $('.discount.discount-amount').hide();
            $('.discount.discount-percentage').show();
        }
    }
});

