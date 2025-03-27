
import { Alert, AlertCircle, AlertTitle, AlertDescription } from "@/components/ui/alert";

const TroubleshootingTips = () => {
  return (
    <Alert className="mt-6 bg-amber-500/10 border-amber-200">
      <AlertCircle className="h-4 w-4" />
      <AlertTitle>Enhanced Troubleshooting Tips</AlertTitle>
      <AlertDescription>
        <ul className="list-disc pl-5 mt-2 space-y-1 text-sm">
          <li>The scraper now includes adaptive mode to detect website structure changes</li>
          <li>For improved reliability, the system will attempt multiple retries with exponential backoff</li>
          <li>Historical data scraping uses specialized settings for better success with older obituaries</li>
          <li>Verify your server allows connections to external websites (check PHP settings like allow_url_fopen)</li>
          <li>If specific regions consistently fail, check for website changes or regional blocks</li>
        </ul>
      </AlertDescription>
    </Alert>
  );
};

export default TroubleshootingTips;
