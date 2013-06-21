<!--
@author Skitsanos.com
-->
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <title>S3_Upload test</title>
        <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
        <script type="text/javascript" src="s3_upload.js"></script>

        <script type="text/javascript">
            var s3_swf1 = s3_swf_init('s3_swf1', {
                signatureUrl: 'signature.php',
                width:  500,
                height: 38,
                onSuccess: function(key){
                    $('#uploadButton').show('slow');

                    this.key = key;
                    //alert(this.key);

                    $('#key').val(this.key);
                    $('#orginal_filename').val(this.fileName);
                    //$('#video_file_size').val(this.fileSize);

                    var arrKey = this.key.split('/');
                    var guid = arrKey[arrKey.length-2];

                    $('#status').html('File has been uploaded. GUID: ' + guid + ', calling Process Video API...');
                    //submit form and send additional parameters;
                    $.post('processVideo.php', {
                        guid: guid,
                        title: 'S3_Upload Automated Sample',
                        description: ''
                    }, function(data){
                        $('#status').html(data);
                    })
                },
                onFailed: function(status){
                    alert(status);
                    $('#uploadButton').show('slow');
                },
                onFileSelected: function(filename, size){
                    this.fileName = filename;
                    this.fileSize = size;
                    uploader_file_field = filename;

                    //alert(this.fileName);
                    //alert(this.fileSize);

                    if ((this.fileSize*1) > (2097152000*1)) {
                        alert("The file you have selected is bigger than the upload limit. Please select a smaller file.");
                    }else{
                        EnableButton();
                    }

                },
                onCancel: function(){
                }
            });

            $(function(){
                $('#uploadButton').click(function(){
                    $('#uploadButton').hide('slow');
                    s3_swf1.upload('s3/');
                });
            });
        </script>
    </head>
    <body>
        <input id="key" name="key" type="hidden" />
        <input id="orginal_filename" name="original_filename" type="hidden" />
        <input id="encoding" name="encoding" type="hidden" value="true" />
        <label class='videoFileStep'>video file to be uploaded</label>
        <br/>

        <div id="s3_swf1">
            Please <a href="http://www.adobe.com/go/getflashplayer">Update</a> your Flash Player to Flash v9.0.1 or higher...
        </div>

        <br/>

        <a id="uploadButton">Upload</a>
        <br/>
        <small><span id="status"></span></small>
    </body>
</html>
