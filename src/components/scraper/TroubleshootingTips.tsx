
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { AlertCircle } from "lucide-react";

const TroubleshootingTips = () => {
  return (
    <Alert className="mt-6 bg-amber-50 dark:bg-amber-500/10 border-amber-200">
      <AlertCircle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
      <AlertTitle className="font-medium">Enhanced Troubleshooting Tips</AlertTitle>
      <AlertDescription>
        <ul className="list-disc pl-5 mt-2 space-y-1.5 text-sm">
          <li>The scraper includes adaptive mode to detect website structure changes</li>
          <li>For improved reliability, the system automatically retries with exponential backoff</li>
          <li>Historical data scraping uses specialized settings optimized for older obituaries</li>
          <li><strong>Authentication verification</strong> prevents publishing of test or fake obituaries</li>
          <li>Verify your server allows connections to external websites (check PHP settings like allow_url_fopen)</li>
          <li>If specific regions consistently fail, check for website changes or regional blocks</li>
          <li>Make sure your hosting provider doesn't block outgoing connections</li>
          <li>Check your PHP memory_limit if you encounter timeout issues with large datasets</li>
          <li>For high-quality data, ensure all text encodings are properly handled (UTF-8 recommended)</li>
          <li>The scraper has built-in data validation to ensure consistency and accuracy</li>
        </ul>
      </AlertDescription>
    </Alert>
  );
};

export default TroubleshootingTips;
